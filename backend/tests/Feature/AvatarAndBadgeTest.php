<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Account photos (users.avatar) and child badges (patients.color / emoji).
 */
class AvatarAndBadgeTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1 x 1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $owner;
    private Patient $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->child = $this->owner->defaultPatient();
        $this->child->update(['name' => 'Aiman']);
    }

    /** A PNG header that claims $width x $height (getimagesize reads only the header). */
    private function pngOfSize(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x06\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    public function test_a_photo_is_saved_shown_and_removed(): void
    {
        Sanctum::actingAs($this->owner);
        $avatar = 'data:image/png;base64,' . self::PNG;

        $this->putJson('/api/user/avatar', ['avatar' => $avatar])->assertOk()->assertJson(['avatar' => $avatar]);
        $this->getJson('/api/user')->assertJson(['avatar' => $avatar]);

        $this->deleteJson('/api/user/avatar')->assertOk();
        $this->assertNull($this->owner->fresh()->avatar);
    }

    public function test_only_real_small_jpeg_png_or_webp_images_are_accepted(): void
    {
        Sanctum::actingAs($this->owner);
        $refused = [
            'svg (can carry scripts)' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'not an image' => 'data:image/png;base64,' . base64_encode('hello, not a picture'),
            'type does not match' => 'data:image/jpeg;base64,' . self::PNG,
            'too many pixels' => 'data:image/png;base64,' . base64_encode($this->pngOfSize(2000, 2000)),
            'not a data url' => 'https://example.test/me.png',
        ];
        foreach ($refused as $why => $avatar) {
            $this->putJson('/api/user/avatar', ['avatar' => $avatar])->assertStatus(422);
            $this->assertNull($this->owner->fresh()->avatar, $why);
        }

        $tooBig = 'data:image/png;base64,' . base64_encode($this->pngOfSize(256, 256) . str_repeat("\0", User::AVATAR_MAX_BYTES));
        $this->putJson('/api/user/avatar', ['avatar' => $tooBig])->assertStatus(422);
    }

    public function test_members_see_each_others_photos(): void
    {
        $grandma = User::factory()->create();
        $grandma->forceFill(['avatar' => 'data:image/png;base64,' . self::PNG])->save();
        $this->child->users()->attach($grandma->id, ['role' => Patient::VIEWER, 'alerts' => false]);

        Sanctum::actingAs($this->owner);
        $members = collect($this->getJson("/api/patients/{$this->child->id}/members")->assertOk()->json('members'));

        $this->assertSame($grandma->avatar, $members->firstWhere('user_id', $grandma->id)['avatar']);
        $this->assertNull($members->firstWhere('user_id', $this->owner->id)['avatar']);
    }

    public function test_the_owner_sets_a_childs_colour_and_emoji(): void
    {
        Device::create(['user_id' => $this->owner->id, 'patient_id' => $this->child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/patients/{$this->child->id}", ['color' => 'rose', 'emoji' => '🦊'])->assertOk();

        $this->getJson('/api/patients')->assertJsonPath('patients.0.color', 'rose')->assertJsonPath('patients.0.emoji', '🦊');
        $this->getJson('/api/devices')->assertJsonPath('devices.0.patient.color', 'rose')->assertJsonPath('devices.0.patient.emoji', '🦊');
        $this->getJson('/api/report')->assertJsonPath('patient.emoji', '🦊');

        // Back to the automatic colour and the initial.
        $this->patchJson("/api/patients/{$this->child->id}", ['color' => null, 'emoji' => null])->assertOk();
        $this->assertNull($this->child->fresh()->color);
        $this->assertNull($this->child->fresh()->emoji);
    }

    public function test_badges_come_from_the_fixed_lists_and_only_owners_change_them(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/patients/{$this->child->id}", ['color' => 'black'])->assertStatus(422);
        $this->patchJson("/api/patients/{$this->child->id}", ['emoji' => '<b>'])->assertStatus(422);

        $caregiver = User::factory()->create();
        $this->child->users()->attach($caregiver->id, ['role' => Patient::CAREGIVER]);
        Sanctum::actingAs($caregiver);
        $this->patchJson("/api/patients/{$this->child->id}", ['color' => 'blue'])->assertForbidden();
        $this->assertNull($this->child->fresh()->color);
    }
}

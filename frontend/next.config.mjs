/** @type {import('next').NextConfig} */
const nextConfig = {
    async rewrites() {
        return [
            {
                source: '/api/:path*',
                // Use backend service name defined in docker-compose
                destination: 'http://backend:8000/api/:path*' 
            }
        ]
    }
};

export default nextConfig;

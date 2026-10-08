FROM python:3.10-slim

WORKDIR /app

# Copy dependency file first to leverage Docker cache
COPY requirements.txt .

# Install system dependencies required for some Python ML packages, install pip packages, then clean up
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc \
    python3-dev \
    && pip install --no-cache-dir -r requirements.txt \
    && apt-get remove -y gcc python3-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Copy the rest of the application
COPY . .

# Expose port for FastAPI
EXPOSE 8000

# Start Uvicorn web server (supports our web application integration)
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]

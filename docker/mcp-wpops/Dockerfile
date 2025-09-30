FROM python:3.11-slim

# Install system dependencies
RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /srv

# Install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy server application
COPY server/ ./server/

# Create non-root user
RUN useradd -m -u 1000 mcpuser && chown -R mcpuser:mcpuser /srv
USER mcpuser

# Expose port
EXPOSE 7070

# Set environment variables
ENV PORT=7070
ENV PYTHONPATH=/srv

# Start the MCP server
WORKDIR /srv
CMD ["python", "-u", "/srv/server/app.py"]
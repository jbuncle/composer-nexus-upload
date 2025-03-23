# Use the official PHP 8.0 CLI image
FROM php:8.0-cli

# Install required system dependencies for ZIP and cURL extensions.
RUN apt-get update && \
    apt-get install -y libzip-dev libcurl4-openssl-dev && \
    docker-php-ext-install zip curl && \
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /workspace

# Copy the nexus-upload script to a directory in the PATH and make it executable.
COPY nexus-upload.php /usr/local/bin/nexus-upload
RUN chmod +x /usr/local/bin/nexus-upload

# Set the default command to run the script.
CMD ["nexus-upload"]

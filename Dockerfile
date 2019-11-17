FROM ubuntu:20.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
        php php-curl php-zip \
    && rm -rf /var/lib/apt/lists/*

ADD nexus-upload.php /usr/bin/nexus-upload
WORKDIR /workspace
CMD "nexus-upload"

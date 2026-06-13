FROM php:8.2-cli

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy all project files
COPY . /app/

# Set working directory
WORKDIR /app

# Expose port (Railway will override via PORT env var)
EXPOSE 8080

# Use PHP built-in server - Railway injects $PORT automatically
CMD php -S 0.0.0.0:${PORT:-8080} -t /app

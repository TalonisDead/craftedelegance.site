# Sử dụng image PHP với Apache
FROM php:8.2-apache

# Cài các thư viện cần thiết, bao gồm cả libonig-dev
RUN docker-php-ext-install mysqli

# Kích hoạt mod_rewrite cho permalink WordPress
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Copy mã nguồn WordPress (giả định bạn đã có sẵn trong thư mục)
COPY . /var/www/html/

# Phân quyền cho thư mục web
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Mở cổng 80
EXPOSE 80

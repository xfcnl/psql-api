FROM php:8.2-apache

# 安装 pdo_mysql 扩展 + 开启 rewrite（.htaccess 需要）
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html

# 拷贝源码（本地开发时会被 docker-compose 的 bind mount 覆盖，实现热更新）
COPY htdocs/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

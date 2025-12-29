FROM php:8.2-apache

# Instalar dependências necessárias
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Configurar Apache para apontar para /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copiar código do projeto
COPY . /var/www/html/

# Criar diretórios necessários com permissões corretas
RUN mkdir -p /var/www/html/uploads/signatures && \
    chmod -R 777 /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html

# Definir diretório de trabalho
WORKDIR /var/www/html

# Expor porta 80
EXPOSE 80

# Iniciar Apache
CMD ["apache2-foreground"]
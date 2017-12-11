FROM wordpress:4.9-php7.1-apache

RUN apt-get update && apt-get install -y \
    unzip \
    wget \
    && rm -rf /var/lib/apt/lists/*

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \ 
	&& chmod +x wp-cli.phar \
	&& mv wp-cli.phar /usr/local/bin/wp \
	&& wp --info

ENV WOOCOMMERCE_VERSION 3.1.2
ENV CITYPAY_PLUGIN_VERSION 1.1.0

COPY scripts/*.sh /usr/local/bin/

WORKDIR /var/www/html
ENTRYPOINT ["citypay-entrypoint.sh"]
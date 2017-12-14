FROM wordpress:4.9-php7.1-apache

RUN apt-get update && apt-get install -y \
    unzip \
    wget \
    less \
    vim \
    && rm -rf /var/lib/apt/lists/*

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \ 
	&& chmod +x wp-cli.phar \
	&& mv wp-cli.phar /usr/local/bin/wp \
	&& wp --info

# Install ngrok to monitor for postbacks
RUN wget https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-386.zip \
    && unzip ngrok-stable-linux-386.zip \
    && cp ngrok /usr/bin/ngrok

ENV WOOCOMMERCE_VERSION 3.2.6
ENV CITYPAY_PLUGIN_VERSION 2.0.0

COPY scripts/*.sh /usr/local/bin/

EXPOSE 80

WORKDIR /var/www/html
ENTRYPOINT ["citypay-entrypoint.sh"]
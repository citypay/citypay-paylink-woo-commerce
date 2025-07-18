FROM wordpress:beta-6.2-RC4-php8.2-apache
LABEL maintainer="Gary Feltham <gary.feltham@citypay.com>"

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    less \
    vim \
    jq \
    && rm -rf /var/lib/apt/lists/*

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
	&& chmod +x wp-cli.phar \
	&& mv wp-cli.phar /usr/local/bin/wp \
	&& wp --info

# Install ngrok to monitor for postbacks
RUN curl -Lo ngrok.zip https://bin.equinox.io/c/bNyj1mQVY4c/ngrok-v3-stable-linux-386.zip \
    && unzip ngrok.zip \
    && cp ngrok /usr/bin/ngrok \
    && rm ngrok.zip

ENV WOOCOMMERCE_VERSION 9.3.3
ENV CITYPAY_PLUGIN_VERSION 2.1.5

COPY scripts/*.sh /usr/local/bin/

EXPOSE 80

WORKDIR /var/www/html
ENTRYPOINT ["citypay-entrypoint.sh"]
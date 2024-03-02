FROM php:alpine

RUN apk update && apk add --no-cache python3 ffmpeg tzdata

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions ./ipe

RUN ./ipe pcntl

RUN rm ./ipe

WORKDIR /app

COPY . .

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN composer install

RUN rm /usr/local/bin/composer

RUN touch /var/log/cron.log

RUN crontab ./crontab

CMD ["/usr/sbin/crond", "-f", "-d", "0"]

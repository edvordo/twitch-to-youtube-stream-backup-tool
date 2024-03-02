FROM php:alpine

RUN apk update && apk add --no-cache python3 ffmpeg tzdata pcntl

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN --from=composer /usr/bin/composer install


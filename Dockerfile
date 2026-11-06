# Matches what's actually running today (WP 7.1.4, PHP 8.3.x) -- the chart's
# image.repository/image.tag get overridden at deploy time to this image.
FROM wordpress:7.1.4-php8.3-apache@sha256:6888208af0e3cb93af427c13a434273b07ba353ce9e2d5a29abe3031bb28ea3a

# bin/image-tag hashes this file's own text, not the commit, so the tag
# alone doesn't say which commit built it -- this label does.
ARG GIT_REVISION=unknown
LABEL org.opencontainers.image.revision=$GIT_REVISION \
      org.opencontainers.image.source="https://github.com/notch8/enact-wp"

# unzip: the base image ships curl + sha256sum but not this.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Two Factor (https://wordpress.org/plugins/two-factor/), pinned and
# checksum-verified. Staged where docker-entrypoint.sh seeds a fresh/empty
# /var/www/html on first boot -- inert on an already-provisioned volume
# (today's staging/production) until notch8/notch8-ops#634's PVC migration
# stops persisting the rest of wp-content underneath it.
ARG TWO_FACTOR_VERSION=0.17.0
ARG TWO_FACTOR_SHA256=4d1b0f8e5d82e24876c2b4e86f119122e28a8dbaf76dfde495173226c3356f37
RUN curl -fsSL -o /tmp/two-factor.zip "https://downloads.wordpress.org/plugin/two-factor.${TWO_FACTOR_VERSION}.zip" \
    && echo "${TWO_FACTOR_SHA256}  /tmp/two-factor.zip" | sha256sum -c - \
    && unzip -q /tmp/two-factor.zip -d /usr/src/wordpress/wp-content/plugins/ \
    && rm /tmp/two-factor.zip

# en_GB core language pack, matching the WordPress version pinned above --
# bump this URL/checksum together with the FROM line.
ARG WP_LANG_EN_GB_SHA256=dfb7ae1343bf750c5a72bd4115fe912873df7bdfe07e555b6b7f82d58af34c1a
RUN mkdir -p /usr/src/wordpress/wp-content/languages \
    && curl -fsSL -o /tmp/en_GB.zip "https://downloads.wordpress.org/translation/core/7.1.4/en_GB.zip" \
    && echo "${WP_LANG_EN_GB_SHA256}  /tmp/en_GB.zip" | sha256sum -c - \
    && unzip -q /tmp/en_GB.zip -d /usr/src/wordpress/wp-content/languages/ \
    && rm /tmp/en_GB.zip

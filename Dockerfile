# Matches what's actually running today (WP 7.1.4, PHP 8.3.x) -- the chart's
# image.repository/image.tag get overridden at deploy time to this image.
FROM wordpress:7.1.4-php8.3-apache@sha256:6888208af0e3cb93af427c13a434273b07ba353ce9e2d5a29abe3031bb28ea3a

# bin/image-tag hashes this file's own text, not the commit, so the tag
# alone doesn't say which commit built it -- this label does.
ARG GIT_REVISION=unknown
LABEL org.opencontainers.image.revision=$GIT_REVISION \
      org.opencontainers.image.source="https://github.com/notch8/enact-wp"

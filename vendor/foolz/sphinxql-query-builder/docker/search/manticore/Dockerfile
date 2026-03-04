FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV MANTICORE_VERSION=2.6.3
ENV LD_LIBRARY_PATH=/opt/manticore/usr/lib/x86_64-linux-gnu:/opt/manticore/usr/lib

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        wget \
        gcc \
        libc6-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/sphinx-tests

RUN wget --quiet -O /opt/sphinx-tests/search.deb \
      "https://github.com/manticoresoftware/manticoresearch/releases/download/${MANTICORE_VERSION}/manticore_${MANTICORE_VERSION}-180328-cccb538-release-stemmer.trusty_amd64-bin.deb" \
    && dpkg -x /opt/sphinx-tests/search.deb /opt/manticore \
    && rm -f /opt/sphinx-tests/search.deb

COPY tests/manticore.conf /opt/sphinx-tests/manticore.conf
COPY tests/ms_test_udf.c /opt/sphinx-tests/ms_test_udf.c

RUN mkdir -p /opt/sphinx-tests/data \
    && gcc -shared -fPIC -I/opt/manticore/usr/include -o /opt/sphinx-tests/data/test_udf.so /opt/sphinx-tests/ms_test_udf.c

EXPOSE 9307 9312

CMD ["/opt/manticore/usr/bin/searchd", "-c", "/opt/sphinx-tests/manticore.conf", "--nodetach"]

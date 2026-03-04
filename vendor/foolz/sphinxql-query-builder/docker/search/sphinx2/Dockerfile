FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV SPHINX2_VERSION=2.3.2-beta

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        wget \
        gcc \
        g++ \
        make \
        autoconf \
        automake \
        libtool \
        pkg-config \
        bison \
        flex \
        libexpat1-dev \
        zlib1g-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/sphinx-tests

RUN wget --quiet "https://sphinxsearch.com/files/sphinx-${SPHINX2_VERSION}.tar.gz" \
    && tar zxf "sphinx-${SPHINX2_VERSION}.tar.gz" \
    && cd "sphinx-${SPHINX2_VERSION}" \
    && ./configure --prefix=/opt/sphinx --without-mysql \
    && make -j"$(nproc)" \
    && make install \
    && cd /opt/sphinx-tests \
    && rm -rf "sphinx-${SPHINX2_VERSION}" "sphinx-${SPHINX2_VERSION}.tar.gz"

COPY tests/sphinx.conf /opt/sphinx-tests/sphinx.conf
COPY tests/test_udf.c /opt/sphinx-tests/test_udf.c

RUN mkdir -p /opt/sphinx-tests/data \
    && gcc -shared -o /opt/sphinx-tests/data/test_udf.so /opt/sphinx-tests/test_udf.c

EXPOSE 9307 9312

CMD ["/opt/sphinx/bin/searchd", "-c", "/opt/sphinx-tests/sphinx.conf", "--nodetach"]

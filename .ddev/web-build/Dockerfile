# Custom Dockerfile for nr_llm TYPO3 extension development
# Adds PHP extensions and tools needed for LLM development

# Install PCOV for fast code coverage using apt-get (pecl not available in DDEV)
RUN apt-get update && apt-get install -y php${PHP_VERSION}-pcov && rm -rf /var/lib/apt/lists/*

# Upgrade PHP to latest patch version (optional - get fixes before DDEV updates)
# RUN apt-get update && apt-get install --only-upgrade -y php${PHP_VERSION}-* && rm -rf /var/lib/apt/lists/*

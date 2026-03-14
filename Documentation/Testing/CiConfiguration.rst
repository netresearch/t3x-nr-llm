.. include:: /Includes.rst.txt

.. _testing-ci-configuration:

================
CI configuration
================

.. _testing-ci:

.. _testing-ci-github:

GitHub Actions
==============

.. code-block:: yaml
   :caption: .github/workflows/tests.yml

   name: Tests

   on: [push, pull_request]

   jobs:
     test:
       runs-on: ubuntu-latest

       strategy:
         matrix:
           php: ['8.2', '8.3', '8.4', '8.5']
           typo3: ['13.4', '14.0']

       steps:
         - uses: actions/checkout@v4

         - name: Setup PHP
           uses: shivammathur/setup-php@v2
           with:
             php-version: ${{ matrix.php }}
             coverage: xdebug

         - name: Install dependencies
           run: composer install --prefer-dist

         - name: Run tests
           run: composer test

         - name: Upload coverage
           uses: codecov/codecov-action@v3
           with:
             files: coverage/clover.xml

.. _testing-ci-gitlab:

GitLab CI/CD
============

.. code-block:: yaml
   :caption: .gitlab-ci.yml

   test:
     image: php:8.2
     script:
       - composer install
       - composer test
     coverage: '/^\s*Lines:\s*\d+.\d+\%/'

## Requirements

* PHP: ^8.1
* guzzlehttp/guzzle: ^7.8
* s1lentium/iptools: ^1.2
* symfony/console: ^6.4 || ^7.0
* symfony/yaml: ^6.4 || ^7.0

Myra PHP Web API Client
======

What is this?
-------------

This library implements a minimal API layer on top of guzzlehttp/guzzle to access the Myra Web API.

You can either use the WebApi Class to access a number of predefined endpoints or use the Signature Middleware with
your own GuzzleHttp/Guzzle instance, to transparently handle authentication and signing of requests.

When using the endpoints please remember that these are very thin abstractions, so they will return plain arrays with
result data. Errors and Exceptions from either Guzzle or the API will have to be handled in you code.

For more flexibility, you can use a GuzzleHttp/Guzzle instance to access the API endpoint directly.
In this case you can simply attach the signature middleware to handle Authentication headers as seen in WebApi::_construct()

    $signature = new Signature($secret, $apiKey);
    $stack->push(
     Middleware::mapRequest(
         function (RequestInterface $request) use ($signature) {
             return $signature->signRequest($request);
         }
     )
    );

This package also contains a commandline client for most API endpoints.
use 'php bin/console list' to list all supported command. Use --help for usage details.

    php bin/console myracloud:api:dns -k <apiKey> -s <apiSecret> -o list <domain>

Installation
------------
Install Composer (https://getcomposer.org/download/)

As Library via Composer:

    composer require cpsit/myra-web-api

As CLI Client:

    composer install --no-dev

### usage

You can create a config.php file in the application root to save your access keys:
```php
<?php
// cli usage
return [
    'apikey' => '##APIKEY##',
    'secret' => '##SECRET##',
    'endpoint' => 'api.myracloud.com' // optional
];
```
#### direct credentals in CLI
```bash
# all arguments for the connection are optional of provided by the config.php except the endpoint this one is always optional
> ./bin/console myracloud:api:dns --apiKey=api_token_key --secret=api_secret --endpoint=api.myracloud.com mydomain.com
# OR
> ./bin/console myracloud:api:dns -k api_token_key -s api_secret -e api.myracloud.com mydomain.com

```

or use the ```Myracloud\WebApi\WebApi``` directly
```php
$myraApi = new Myracloud\WebApi\WebApi(
    apiKey: 'api_token_key',    // provided from myracloud.com
    secret: 'api_secret',       // provided from myracloud.com
    site: 'api_endpoint_domain',// leave empty to use default: 'api.myracloud.com'
    lang: 'api_endpoint_lang',  // leave empty to use default: 'en'
    connectionConfig: [],       // connectionConfig for guzzleClient, with this can every default config be overwritten (default: base_uri, handler)
    requestHandler: null        // custom request handler, default is GuzzleHttp\Handler\CurlHandler used in guzzle/HandlerStack, can be replaced for example with a MockHandler for testing
);

// example
print_r($myraApi->getDnsRecordEndpoint()->getList());
```

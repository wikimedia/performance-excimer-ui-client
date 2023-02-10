## Excimer: A low-overhead sampling profiler for PHP — UI Client

### Getting started

Install the PHP extension:

```
pecl install excimer
```

Install the server.

Activate the profiler in your app. This package provides a single class which
does not depend on the Composer autoloader. So you can use it from a file
configured with `auto_prepend_file` in `php.ini`.

```php
require './vendor/wikimedia/excimer-ui-client/src/ExcimerClient.php';
\Wikimedia\ExcimerUI\Client\ExcimerClient::setup( [
    'url' => 'https://localhost/excimer/index.php',
] );
```

Show a link to the profile in an appropriate place in your app:

```php
use Wikimedia\ExcimerUI\Client\ExcimerClient;
if ( ExcimerClient::isActive() ) {
    echo ExcimerClient::singleton()->makeLink();
}
```

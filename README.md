# Stuart Place Trip Planner

Interactive California Coast + Yosemite road trip planner.

## Mobile app trip-data webservice

The iOS app fetches current trip data from:

```text
/california-trip/api/trip-data.php
```

The endpoint returns the JSON source at:

```text
/california-trip/trip-data.json
```

Requests must include both headers:

```text
X-Stuartplace-Client: california-trip-ios
Authorization: Bearer <app token>
```

Configure the accepted token in the private `stuartplace-config.php` file outside `public_html`:

```php
'mobile_api_tokens' => [
    'california-trip-ios' => 'sha256:<sha256 of the app token>',
],
```

Only store the hash in the website config; the app sends the bearer token at runtime.

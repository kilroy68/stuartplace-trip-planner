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

## SmugMug trip photos

The California trip photo setting accepts either one SmugMug gallery URL or a parent
folder URL. When a parent folder is configured, map sync discovers every gallery
directly inside that folder and imports the geotagged photos from all of them.

The mobile photo uploader keeps working with a parent folder by choosing the
highest-numbered `Day N` gallery as its upload destination.

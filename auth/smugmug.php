<?php

function smugmug_get_json(string $url, string $apiKey): array {
    $sep = strpos($url, '?') === false ? '?' : '&';
    $url .= $sep . 'APIKey=' . rawurlencode($apiKey) . '&_accept=application%2Fjson';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http < 200 || $http >= 300) {
        throw new RuntimeException($err ?: 'SmugMug API request failed with HTTP ' . $http);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('SmugMug returned an unreadable API response.');
    }
    return $json;
}

function smugmug_find_resource_uri($value, string $resource): ?string {
    $pattern = $resource === 'album'
        ? '~^/api/v2/album/[A-Za-z0-9]+~'
        : '~^/api/v2/folder/(?:id/[A-Za-z0-9]+|user/[^\s?]+)~';
    if (is_string($value) && preg_match($pattern, $value, $m)) {
        return $m[0];
    }
    if (is_array($value)) {
        foreach ($value as $child) {
            $found = smugmug_find_resource_uri($child, $resource);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

function smugmug_normalize_api_url(string $uri): string {
    if (preg_match('~^https://api\.smugmug\.com/~i', $uri)) {
        return $uri;
    }
    return 'https://api.smugmug.com/' . ltrim($uri, '/');
}

function smugmug_source_from_gallery(string $gallery, string $apiKey): array {
    $gallery = trim($gallery);
    if ($gallery === '') {
        throw new RuntimeException('Save the SmugMug gallery or parent folder URL first.');
    }

    if (preg_match('~/api/v2/album/([A-Za-z0-9]+)~', $gallery, $m) || preg_match('~/album/([A-Za-z0-9]+)~', $gallery, $m)) {
        return ['type' => 'album', 'uri' => '/api/v2/album/' . $m[1]];
    }

    if (preg_match('~/(?:n-|node/)([A-Za-z0-9]+)~', $gallery, $m)) {
        $node = smugmug_get_json('https://api.smugmug.com/api/v2/node/' . rawurlencode($m[1]), $apiKey);
        $albumUri = smugmug_find_resource_uri($node['Response']['Node']['Uris']['Album']['Uri'] ?? null, 'album')
            ?? smugmug_find_resource_uri($node, 'album');
        if ($albumUri !== null) {
            return ['type' => 'album', 'uri' => $albumUri];
        }
        $folderUri = smugmug_find_resource_uri($node['Response']['Node']['Uris']['Folder']['Uri'] ?? null, 'folder')
            ?? smugmug_find_resource_uri($node, 'folder');
        if ($folderUri !== null) {
            return ['type' => 'folder', 'uri' => $folderUri];
        }
    }

    $parts = parse_url($gallery);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    if ($host === '' || $path === '') {
        throw new RuntimeException('Paste the full SmugMug gallery or parent folder URL.');
    }

    $nickname = '';
    if (preg_match('~^([a-z0-9-]+)\.smugmug\.com$~i', $host, $m)) {
        $nickname = $m[1];
    } elseif (preg_match('~^www\.([a-z0-9-]+)\.smugmug\.com$~i', $host, $m)) {
        $nickname = $m[1];
    }
    if ($nickname === '') {
        throw new RuntimeException('Please paste the normal SmugMug URL, like https://yourname.smugmug.com/Folder/Gallery. Custom domains are not supported for sync yet.');
    }

    // If someone pasted a photo URL, resolve the containing gallery.
    $path = preg_replace('~/i-[A-Za-z0-9]+.*$~', '', $path) ?: $path;
    $path = '/' . trim($path, '/');
    $lookupBase = 'https://api.smugmug.com/api/v2/user/' . rawurlencode($nickname) . '!urlpathlookup';
    $lastError = '';
    foreach ([$lookupBase . '?urlpath=' . rawurlencode($path), $lookupBase . '?UrlPath=' . rawurlencode($path)] as $url) {
        try {
            $json = smugmug_get_json($url, $apiKey);
            $albumUri = smugmug_find_resource_uri($json['Response']['Album']['Uri'] ?? null, 'album');
            if ($albumUri !== null) {
                return ['type' => 'album', 'uri' => $albumUri];
            }
            $folderUri = smugmug_find_resource_uri($json['Response']['Folder']['Uri'] ?? null, 'folder');
            if ($folderUri !== null) {
                return ['type' => 'folder', 'uri' => $folderUri];
            }
            $lastError = 'SmugMug found the URL, but it was not an album or folder.';
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
    }
    throw new RuntimeException('Could not resolve that SmugMug URL. ' . $lastError);
}

function smugmug_paginated_objects(string $uri, string $responseKey, string $apiKey): array {
    $objects = [];
    $next = $uri;
    $visited = [];
    while ($next !== '') {
        $url = smugmug_normalize_api_url($next);
        if (isset($visited[$url])) {
            throw new RuntimeException('SmugMug returned a repeated pagination link.');
        }
        $visited[$url] = true;
        if (count($visited) > 100) {
            throw new RuntimeException('SmugMug returned too many pages to sync safely.');
        }
        $json = smugmug_get_json($url, $apiKey);
        $pageObjects = $json['Response'][$responseKey] ?? [];
        if (isset($pageObjects['Uri'])) {
            $pageObjects = [$pageObjects];
        }
        if (is_array($pageObjects)) {
            foreach ($pageObjects as $object) {
                if (is_array($object)) {
                    $objects[] = $object;
                }
            }
        }
        $next = (string)($json['Response']['Pages']['NextPage'] ?? '');
    }
    return $objects;
}

function smugmug_albums_from_gallery(string $gallery, string $apiKey): array {
    $source = smugmug_source_from_gallery($gallery, $apiKey);
    if ($source['type'] === 'album') {
        $json = smugmug_get_json(smugmug_normalize_api_url($source['uri']), $apiKey);
        $album = $json['Response']['Album'] ?? [];
        if (!is_array($album) || empty($album['Uri'])) {
            $album = ['Uri' => $source['uri']];
        }
        return ['source' => $source, 'albums' => [$album]];
    }

    $albums = smugmug_paginated_objects($source['uri'] . '!albums?count=500', 'Album', $apiKey);
    if ($albums === []) {
        throw new RuntimeException('The SmugMug parent folder does not contain any galleries.');
    }
    return ['source' => $source, 'albums' => $albums];
}

function smugmug_images_from_albums(array $albums, string $apiKey): array {
    $images = [];
    foreach ($albums as $album) {
        $albumUri = (string)($album['Uri'] ?? '');
        if ($albumUri === '') {
            continue;
        }
        $albumImages = smugmug_paginated_objects($albumUri . '!images?count=500', 'AlbumImage', $apiKey);
        foreach ($albumImages as $image) {
            $images[] = $image;
        }
    }
    return $images;
}

function smugmug_rebuild_trip_photos(PDO $pdo, string $gallery, string $apiKey, string $createdBy): array {
    $resolved = smugmug_albums_from_gallery($gallery, $apiKey);
    $albums = $resolved['albums'];
    // Finish every remote request before replacing the current map data.
    $images = smugmug_images_from_albums($albums, $apiKey);
    $stmt = $pdo->prepare('INSERT INTO trip_photos (smugmug_key,title,caption,thumb_url,photo_url,latitude,longitude,taken_at,created_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), caption=VALUES(caption), thumb_url=VALUES(thumb_url), photo_url=VALUES(photo_url), latitude=VALUES(latitude), longitude=VALUES(longitude), taken_at=VALUES(taken_at)');
    $count = 0;
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM trip_photos');
        foreach ($images as $img) {
            $lat = $img['Latitude'] ?? $img['Lat'] ?? null;
            $lng = $img['Longitude'] ?? $img['Lon'] ?? null;
            if ($lat === null || $lng === null || $lat === '' || $lng === '') {
                continue;
            }
            $key = $img['ImageKey'] ?? $img['Key'] ?? md5(json_encode($img));
            $thumb = trim((string)($img['ThumbnailUrl'] ?? $img['ThumbUrl'] ?? ''));
            $photoUrl = trim((string)($img['WebUri'] ?? $img['ArchivedUri'] ?? ''));
            if ($thumb === '') {
                $thumb = $photoUrl;
            }
            if ($photoUrl === '' || $thumb === '') {
                continue;
            }
            $stmt->execute([
                $key,
                $img['Title'] ?? $img['FileName'] ?? 'Trip photo',
                $img['Caption'] ?? null,
                $thumb,
                $photoUrl,
                (float)$lat,
                (float)$lng,
                $img['DateTimeOriginal'] ?? $img['Date'] ?? null,
                $createdBy,
            ]);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'imported' => $count,
        'albums' => count($albums),
        'albumNames' => array_values(array_map(static function (array $album): string {
            return (string)($album['Title'] ?? $album['Name'] ?? $album['UrlName'] ?? $album['AlbumKey'] ?? 'SmugMug gallery');
        }, $albums)),
    ];
}

function smugmug_upload_album_from_gallery(string $gallery, string $apiKey): array {
    $resolved = smugmug_albums_from_gallery($gallery, $apiKey);
    $albums = $resolved['albums'];
    if (count($albums) === 1) {
        return $albums[0];
    }

    usort($albums, static function (array $a, array $b): int {
        $aName = (string)($a['Title'] ?? $a['Name'] ?? $a['UrlName'] ?? '');
        $bName = (string)($b['Title'] ?? $b['Name'] ?? $b['UrlName'] ?? '');
        preg_match('/\bday\s*[-_ ]?(\d+)\b/i', $aName, $aDay);
        preg_match('/\bday\s*[-_ ]?(\d+)\b/i', $bName, $bDay);
        $aNumber = isset($aDay[1]) ? (int)$aDay[1] : -1;
        $bNumber = isset($bDay[1]) ? (int)$bDay[1] : -1;
        if ($aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }
        return strnatcasecmp($aName, $bName);
    });
    return $albums[count($albums) - 1];
}

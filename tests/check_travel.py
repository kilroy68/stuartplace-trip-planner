"""Verify map completeness and road geometry continuity for the published itinerary."""
import json
import math
import pathlib
import xml.etree.ElementTree as ET
root = pathlib.Path(__file__).resolve().parents[1]
states = json.loads((root/'assets/states.json').read_text())
svg = ET.parse(root/'assets/us-map.svg')
paths = svg.findall('{http://www.w3.org/2000/svg}path')
assert len(states) == len(set(states)) == 50
assert {p.attrib['data-state'] for p in paths} == set(states)
assert all(p.attrib.get('tabindex') == '0' for p in paths)
stops = json.loads((root/'new-england-trip/itinerary.json').read_text())
assert len({s['id'] for s in stops}) == 7
assert sum(s['nights'] for s in stops) == 11
assert stops[0]['date'] == '2027-10-03' and stops[-1]['date'] == '2027-10-14'
route = json.loads((root/'new-england-trip/route.json').read_text())['geometry']
assert route['type'] == 'LineString' and len(route['coordinates']) > 1000
points = route['coordinates']
for s in stops:
    assert min(math.hypot(x-s['lng'], y-s['lat']) for x,y in points) < 0.01, s['name']
assert max(math.hypot(a[0]-b[0],a[1]-b[1]) for a,b in zip(points,points[1:])) < .1
for p in ['index.php','new-england-trip/index.php','future-trips/index.php','api/travel.php']:
    text=(root/p).read_text()
    assert 'auth.php' in text
api_text = (root/'api/travel.php').read_text()
california_api_text = (root/'auth/api.php').read_text()
california_page_text = (root/'california-trip/index.php').read_text()
future_page_text = (root/'future-trips/index.php').read_text()
auth_text = (root/'auth/auth.php').read_text()
smugmug_connect_text = (root/'california-trip/api/smugmug-connect.php').read_text()
mobile_upload_text = (root/'california-trip/api/upload-photo.php').read_text()
home_text = (root/'index.php').read_text()
assert 'auth_is_admin()' in api_text
assert 'hash_equals(auth_csrf_token()' in api_text
assert "person='Together'" in api_text and "$in['person']" not in api_text
assert "Where we've been together" in home_text and 'id="person"' not in home_text
assert '/future-trips/' in home_text
assert all(action in api_text for action in ['future_trip_add','future_trip_delete','future_trip_reorder'])
assert "if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')" in california_api_text
assert 'api_require_admin();' in california_api_text
assert "strpos($contentType, 'application/json') !== 0" in california_api_text
assert "headers:{'Content-Type':'application/json'}" in california_page_text
assert '$canEdit=auth_is_admin();' in future_page_text and '<?php if ($canEdit): ?>' in future_page_text
assert "auth_find_allowed_user((string)$u['email'])" in auth_text
assert "$action = $_POST['action'] ?? '';" in smugmug_connect_text and 'auth_verify_csrf();' in smugmug_connect_text
write_guards = {
    'api/travel.php': ['auth_is_admin()', 'hash_equals(auth_csrf_token()'],
    'auth/api.php': ['api_require_admin();', "strpos($contentType, 'application/json') !== 0"],
    'auth/users.php': ['auth_require_admin();', 'auth_verify_csrf();'],
    'california-trip/api/smugmug-connect.php': ['auth_require_admin();', 'auth_verify_csrf();'],
    'california-trip/api/upload-photo.php': ['mobile_authenticated_admin_client()', "REQUEST_METHOD'] ?? 'GET') !== 'POST'"],
}
for path, guards in write_guards.items():
    source = (root/path).read_text()
    assert all(guard in source for guard in guards), (path, guards)
assert 'administrator-equivalent credential' in mobile_upload_text
print('PASS: 50 accessible state paths, together-only tracking, future-trip CRUD and priority controls, 11 nights, 7 stops, continuous road geometry near every stop, authentication guards.')

"""Run against the local app: python3 tests/csrf_http.py [base URL]."""
import http.cookiejar
import json
import sys
import urllib.error
import urllib.request

base = (sys.argv[1] if len(sys.argv) > 1 else 'http://localhost/combine-data-2').rstrip('/')
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def submit(fmt, valid=True):
    with client.open(base + '/reconciliation/csrf') as response:
        token = json.load(response)
    boundary = '----reconciliation-csrf-regression'
    fields = [
        (token['name'], token['hash'] if valid else 'invalid', None),
        ('outputFormat', fmt, None),
        ('lkpFile', 'x|100|x|USD|0001|100|100|0000\n', 'fixture.txt'),
        ('tbFile', 'CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n0001-x-10\t6\tUSD\t10\t10\n', 'fixture.tsv'),
    ]
    parts = []
    for name, value, filename in fields:
        disposition = 'Content-Disposition: form-data; name="' + name + '"'
        if filename:
            disposition += '; filename="' + filename + '"'
        parts.append('--' + boundary + '\r\n' + disposition + '\r\n\r\n' + value + '\r\n')
    request = urllib.request.Request(
        base + '/process', data=(''.join(parts) + '--' + boundary + '--\r\n').encode(),
        headers={'Content-Type': 'multipart/form-data; boundary=' + boundary,
                 'X-Requested-With': 'XMLHttpRequest'})
    try:
        response = client.open(request)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        data = response.read()
        if not valid:
            assert response.status == 403, 'Invalid CSRF token must be rejected'
        else:
            assert response.status == 200, (response.status, data[:200])
            assert data.startswith(b'PK' if fmt == 'xlsx' else b'\xef\xbb\xbfbranch;')
            assert 'hasil-kombinasi.' + fmt in response.headers['Content-Disposition']


submit('csv')
submit('xlsx')  # Same cookie jar, after token regeneration.
submit('csv', valid=False)
submit('csv')  # Recovery after a rejected POST.
assert any(cookie.name == 'combine_data_csrf' and cookie.path == '/' for cookie in jar)
print('CSRF HTTP checks passed: CSV, XLSX, invalid token, recovery')

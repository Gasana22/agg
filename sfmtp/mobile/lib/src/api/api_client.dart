import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import 'token_store.dart';

/// An RFC 9457 problem from the API (docs/06 §1).
class ApiException implements Exception {
  ApiException(this.status, this.code, this.title, [this.body = const {}]);

  final int status;
  final String code;
  final String title;
  final Map<String, dynamic> body;

  /// No response at all: offline, DNS, timeout.
  bool get isNetwork => status == 0;

  /// This phone was signed out from the web: its local data must go (docs/08 §5).
  bool get isDeviceRevoked => code == 'device_revoked';

  @override
  String toString() => 'ApiException($status $code: $title)';
}

/// A thin client for the endpoints the field app uses. Every POST carries an
/// Idempotency-Key (required from mobile); an expired access token is
/// refreshed once with the rotating refresh token.
class ApiClient {
  ApiClient({required this.baseUrl, required this.tokens, http.Client? httpClient}) : _http = httpClient ?? http.Client();

  final String baseUrl;
  final TokenStore tokens;
  final http.Client _http;
  static const _uuid = Uuid();

  /// Signs in. Returns an MFA token when the account uses a second factor:
  /// finish with [mfaChallenge] and the code from the authenticator app.
  Future<String?> login(String email, String password, {String deviceName = 'SFMTP phone', String platform = 'android'}) async {
    final res = await _send('POST', '/auth/login', body: {
      'email': email,
      'password': password,
      'client': 'mobile',
      'device': {'name': deviceName, 'platform': platform},
    }, auth: false);
    final data = res['data'] as Map<String, dynamic>;
    if (data['mfa_required'] == true) return data['mfa_token'] as String;
    await tokens.save(data['access_token'] as String, data['refresh_token'] as String);
    return null;
  }

  Future<void> mfaChallenge(String mfaToken, String code) async {
    final data = (await _send('POST', '/auth/mfa/challenge', body: {'mfa_token': mfaToken, 'code': code.replaceAll(' ', '')}, auth: false))['data'] as Map<String, dynamic>;
    await tokens.save(data['access_token'] as String, data['refresh_token'] as String);
  }

  Future<void> logout() async {
    try {
      await _send('POST', '/auth/logout', body: {});
    } catch (_) {
      // Signing out locally still works offline.
    }
    await tokens.clear();
  }

  Future<List<Map<String, dynamic>>> workspaces() async =>
      ((await _send('GET', '/me/workspaces'))['data'] as List).cast<Map<String, dynamic>>();

  Future<Map<String, dynamic>> pull(String farmId, {String? cursor, int limit = 500}) async =>
      (await _send('GET', '/farms/$farmId/sync/pull', query: {'cursor': ?cursor, 'limit': '$limit'}))['data'] as Map<String, dynamic>;

  Future<void> registerPushToken(String? token, {String platform = 'fcm'}) =>
      _put('/me/devices/current/push-token', {'token': token, 'platform': token == null ? null : platform});

  Future<void> _put(String path, Map<String, dynamic> body) async {
    Future<http.Response> once() async => _http.put(Uri.parse('$baseUrl$path'), headers: {...await _headers(json: true), 'Idempotency-Key': _uuid.v7()}, body: jsonEncode(body));
    var res = await _guard(once);
    if (res.statusCode == 401 && await _refresh()) res = await _guard(once);
    _decode(res);
  }

  Future<List<Map<String, dynamic>>> push(String farmId, List<Map<String, dynamic>> mutations) async =>
      (((await _send('POST', '/farms/$farmId/sync/push', body: {'mutations': mutations}))['data'] as Map)['results'] as List).cast<Map<String, dynamic>>();

  /// Upload a photo; the server deduplicates by SHA-256 and answers with the media id.
  Future<String> upload(String farmId, Uint8List bytes, String sha256, String filename) async {
    Future<http.StreamedResponse> send() async {
      final req = http.MultipartRequest('POST', Uri.parse('$baseUrl/farms/$farmId/media/uploads'))
        ..headers.addAll(await _headers(json: false))
        ..headers['Idempotency-Key'] = _uuid.v7()
        ..fields['sha256'] = sha256
        ..files.add(http.MultipartFile.fromBytes('file', bytes, filename: filename));
      return _http.send(req);
    }

    var res = await _guard(() async => http.Response.fromStream(await send()));
    if (res.statusCode == 401 && await _refresh()) {
      res = await _guard(() async => http.Response.fromStream(await send()));
    }
    return (_decode(res)['data'] as Map)['id'] as String;
  }

  Future<Map<String, dynamic>> _send(String method, String path, {Map<String, dynamic>? body, Map<String, String>? query, bool auth = true}) async {
    Future<http.Response> once() async {
      final uri = Uri.parse('$baseUrl$path').replace(queryParameters: query);
      final headers = await _headers(json: true, auth: auth);
      if (method == 'GET') return _http.get(uri, headers: headers);
      return _http.post(uri, headers: {...headers, 'Idempotency-Key': _uuid.v7()}, body: jsonEncode(body ?? {}));
    }

    var res = await _guard(once);
    if (res.statusCode == 401 && auth && await _refresh()) {
      res = await _guard(once);
    }
    return _decode(res);
  }

  /// Rotates the tokens. A refused refresh signs the phone out; a revoked
  /// device is reported as such so the app can wipe its data.
  Future<bool> _refresh() async {
    final refresh = await tokens.refreshToken();
    if (refresh == null) return false;
    final res = await _guard(() => _http.post(Uri.parse('$baseUrl/auth/refresh'),
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'Idempotency-Key': _uuid.v7()},
        body: jsonEncode({'refresh_token': refresh})));
    if (res.statusCode != 200) {
      await tokens.clear();
      if (res.statusCode == 401) {
        try {
          if ((jsonDecode(res.body) as Map)['code'] == 'device_revoked') throw ApiException(401, 'device_revoked', 'This phone was signed out.');
        } on FormatException catch (_) {}
      }
      return false;
    }
    final data = (jsonDecode(res.body) as Map)['data'] as Map<String, dynamic>;
    await tokens.save(data['access_token'] as String, data['refresh_token'] as String);
    return true;
  }

  Future<Map<String, String>> _headers({required bool json, bool auth = true}) async {
    final access = auth ? await tokens.accessToken() : null;
    return {
      'Accept': 'application/json',
      if (json) 'Content-Type': 'application/json',
      if (access != null) 'Authorization': 'Bearer $access',
    };
  }

  Future<http.Response> _guard(Future<http.Response> Function() call) async {
    try {
      return await call().timeout(const Duration(seconds: 30));
    } catch (e) {
      throw ApiException(0, 'network', 'No connection to the server.');
    }
  }

  Map<String, dynamic> _decode(http.Response res) {
    Map<String, dynamic> body = {};
    try {
      if (res.body.isNotEmpty) body = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {}
    if (res.statusCode >= 200 && res.statusCode < 300) return body;
    throw ApiException(res.statusCode, (body['code'] as String?) ?? 'http_${res.statusCode}', (body['title'] as String?) ?? 'The server refused the request.', body);
  }
}

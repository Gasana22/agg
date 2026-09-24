import 'package:geolocator/geolocator.dart';

class Place {
  const Place(this.lat, this.lng, this.accuracyM);
  final double lat;
  final double lng;
  final double accuracyM;

  Map<String, dynamic> toJson() => {'lat': lat, 'lng': lng, 'accuracy_m': accuracyM};
}

/// The phone's position, only asked for when the worker checks in or works
/// on a task (docs/08 §5). Null when location is off or refused.
abstract class LocationSource {
  Future<Place?> current();
}

class DeviceLocation implements LocationSource {
  @override
  Future<Place?> current() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return null;
      final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 10)));
      return Place(_round(p.latitude), _round(p.longitude), (p.accuracy * 10).round() / 10);
    } catch (_) {
      return null;
    }
  }

  static double _round(double v) => (v * 1e6).round() / 1e6;
}

class NoLocation implements LocationSource {
  const NoLocation([this.place]);
  final Place? place;

  @override
  Future<Place?> current() async => place;
}

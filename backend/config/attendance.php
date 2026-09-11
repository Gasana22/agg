<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Check-in Geofence Radius
    |--------------------------------------------------------------------------
    |
    | How far (in meters) a check-in's GPS point may be from the farm's own
    | reference point (Farm.gps_lat/gps_lng) before it's rejected. Only
    | enforced when the farm has a reference point set — a farm without one
    | has nothing to geofence against. The default is generous because a
    | farm's stored point is a single reference coordinate, not a precise
    | boundary survey.
    |
    */

    'geofence_radius_meters' => (int) env('ATTENDANCE_GEOFENCE_RADIUS_METERS', 1000),

];

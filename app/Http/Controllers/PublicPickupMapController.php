<?php

namespace App\Http\Controllers;

use App\Http\Requests\{PublicPickupMapRequest, PublicReturnMapRequest};
use App\Services\Geocoding\{NominatimSearch, PlaceSearchUnavailable, PlaceSelection};
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class PublicPickupMapController extends Controller
{
    public function search(Request $request, NominatimSearch $search)
    {
        $this->authorizePreview($request);
        $data = $request->validate(['query' => ['required', 'string', 'min:3', 'max:200']]);
        try {
            $response = response()->json(['places' => $search->searchArea($data['query'])]);
        } catch (PlaceSearchUnavailable $exception) {
            $response = response()->json(['message' => $exception->getMessage()], 503);
        }
        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function address(Request $request, NominatimSearch $search)
    {
        $this->authorizePreview($request);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-85.05112878,85.05112878'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        try {
            $response = response()->json(['label' => $search->reverse((float) $data['lat'], (float) $data['lng'])]);
        } catch (PlaceSearchUnavailable $exception) {
            $response = response()->json(['message' => $exception->getMessage()], $exception->getCode() === 429 ? 429 : 503);
        }
        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(PublicPickupMapRequest $request, PlaceSelection $selection)
    {
        $this->authorizePreview($request);
        $data = $request->validated();
        $point = ['label' => $data['delivery_address'], 'lat' => round((float) $data['map_lat'], 7),
            'lng' => round((float) $data['map_lng'], 7), 'source' => 'map'];
        $choice = $selection->issue([$point])[0];
        $filters = Arr::only($data, ['pickup_at', 'return_at', 'place_id', 'city', 'request_delivery', 'delivery_address', 'request_custom_return', 'return_address', 'return_place']);
        $filters['delivery_place'] = $choice['token'];

        return redirect()->route(($request->routeIs('public-cars.preview.*') ? 'public-cars.preview' : 'public-cars').'.index', $filters, 303)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function storeReturn(PublicReturnMapRequest $request, PlaceSelection $selection)
    {
        $this->authorizePreview($request);
        $data = $request->validated();
        $point = ['label' => $data['return_address'], 'lat' => round((float) $data['map_lat'], 7),
            'lng' => round((float) $data['map_lng'], 7), 'source' => 'map'];
        $choice = $selection->issue([$point], 'public-return')[0];

        return response()->json(['selection' => $choice, 'point' => $point])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function authorizePreview(Request $request): void
    {
        if (!$request->routeIs('public-cars.preview.*')) return;
        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active, 403);
        if (!$request->user()->hasRole('admin')) abort_unless($request->user()->organization?->is_active, 403);
    }
}

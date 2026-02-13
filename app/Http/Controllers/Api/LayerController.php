<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LayerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $layers = Layer::where('user_id', $request->user()->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json($layers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'layer_type' => 'sometimes|string|in:vector,wms,xyz,group',
            'parent_id' => 'sometimes|nullable|integer|exists:layers,id',
            'source_url' => 'sometimes|nullable|string|max:1024',
            'wms_layers' => 'sometimes|nullable|string|max:255',
            'geometry_type' => 'sometimes|string|in:Point,LineString,Polygon,any',
            'style' => 'sometimes|array',
        ]);

        $layer = Layer::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'sort_order' => Layer::where('user_id', $request->user()->id)->max('sort_order') + 1,
        ]);

        return response()->json($layer, 201);
    }

    public function show(Layer $layer): JsonResponse
    {
        return response()->json($layer);
    }

    public function update(Request $request, Layer $layer): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'parent_id' => 'sometimes|nullable|integer|exists:layers,id',
            'style' => 'sometimes|nullable|array',
            'schema' => 'sometimes|nullable|array',
        ]);

        $layer->update($validated);

        return response()->json($layer);
    }

    public function destroy(Layer $layer): JsonResponse
    {
        $layer->delete();

        return response()->json(null, 204);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:layers,id',
        ]);

        foreach ($validated['order'] as $index => $layerId) {
            Layer::where('id', $layerId)
                ->where('user_id', $request->user()->id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Reordered']);
    }
}

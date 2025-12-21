<?php

namespace App\Http\Controllers;

use App\Models\Categories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'search' => 'sometimes|nullable|string|max:255',
            'limit'  => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $search = $request->query('search');
        $limit  = $request->filled('limit') ? (int) $request->query('limit') : null;

        $query = Categories::query()->orderBy('name');

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $categories = $query->get();

        $mapped = $categories->map(function (Categories $category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->icon,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Categories retrieved successfully',
            'data' => [
                'categories' => $mapped,
            ],
        ], 200);
    }
}
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUpgradePackageRequest;
use App\Http\Resources\UpgradePackageResource;
use App\Models\UpgradePackage;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpgradePackageController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', UpgradePackage::class);

        $packages = UpgradePackage::query()->orderByDesc('released_at')->orderByDesc('id')->get();

        return response()->json(['data' => UpgradePackageResource::collection($packages)->resolve()]);
    }

    public function store(StoreUpgradePackageRequest $request): JsonResponse
    {
        $this->authorize('create', UpgradePackage::class);

        $data = $request->validated();

        $package = UpgradePackage::create(array_merge([
            'arch' => 'amd64',
            'channel' => UpgradePackage::CHANNEL_STABLE,
            'rollout_percent' => 100,
            'is_active' => true,
            'released_at' => now(),
        ], $data));

        AuditLogger::log('upgrade_package.released', $package, [
            'version' => $package->version,
            'arch' => $package->arch,
            'rollout_percent' => $package->rollout_percent,
        ], $request->user()?->name, $request->ip());

        return response()->json(['data' => (new UpgradePackageResource($package))->resolve()], 201);
    }

    public function update(Request $request, UpgradePackage $package): JsonResponse
    {
        $this->authorize('update', $package);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $package->fill($data)->save();

        AuditLogger::log('upgrade_package.updated', $package, $data, $request->user()?->name, $request->ip());

        return response()->json(['data' => (new UpgradePackageResource($package->refresh()))->resolve()]);
    }

    public function destroy(Request $request, UpgradePackage $package): JsonResponse
    {
        $this->authorize('delete', $package);

        AuditLogger::log('upgrade_package.deleted', $package, ['version' => $package->version], $request->user()?->name, $request->ip());
        $package->delete();

        return response()->json(['ok' => true]);
    }
}

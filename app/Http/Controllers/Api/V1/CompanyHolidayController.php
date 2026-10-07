<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Holidays\StoreCompanyHolidayRequest;
use App\Http\Resources\Api\V1\CompanyHolidayResource;
use App\Models\CompanyHoliday;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CompanyHolidayController extends Controller
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CompanyHoliday::query();

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        return CompanyHolidayResource::collection($query->orderBy('start_date')->get());
    }

    public function store(StoreCompanyHolidayRequest $request): JsonResponse
    {
        $this->authorize('create', CompanyHoliday::class);

        $startDate = Carbon::parse($request->string('start_date'));
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->string('end_date'))
            : $startDate->copy();

        $daysCount = (int) $startDate->diffInDays($endDate) + 1;

        $holiday = CompanyHoliday::create([
            'name' => $request->string('name'),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'days_count' => $daysCount,
            'year' => $startDate->year,
        ]);

        $this->auditLogger->log('create_holiday', $holiday, null, $holiday->toArray());

        return (new CompanyHolidayResource($holiday))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(CompanyHoliday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $old = $holiday->toArray();
        $holiday->delete();
        $this->auditLogger->log('delete_holiday', $holiday, $old, null);

        return response()->json([
            'message' => 'Holiday deleted successfully.',
        ]);
    }
}

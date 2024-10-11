<?php

namespace App\Http\Controllers\AdvocacyV2;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarActivitiesResource;
use App\Models\AdvocacyV2\Activity;
use App\Models\AdvocacyV2\Frequency;
use App\Models\AdvocacyV2\TehsilActivity;
use App\Models\District;
use App\Models\Tehsil;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view advocacy activities calendar', ['only' => ['getCalendar', 'getActivitiesCalendar']]);
    }

    public function getCalendar(Request $request): \Inertia\Response
    {
        $request->merge(['search' => '']);
        $request->merge(['activity' => '']);
        $request->merge(['tehsil' => '']);
        $request->merge(['frequency' => '']);
        $request->merge(['assignment' => '']);
        $request->merge(['activity_type' => '']);
        $assignmentOptions = [
            [
                "id" => 'assigned',
                "text" => "Assigned",
                "selected" => false
            ], [
                "id" => 'performed',
                "text" => 'Performed',
                "selected" => false
            ], [
                "id" => 'expired',
                "text" => "Expired",
                "selected" => false
            ],
        ];
        return Inertia::render('AdvocacyV2/Schedules/Calendar', [
            'activitiesOptions' => makeSelect2DropdownOptions(new Activity()),
            'frequencyOptions' => makeSelect2DropdownOptions(new Frequency()),
            'activity_type_options' => get_activity_type_dropdown_options(),
            'assignmentOptions' => $assignmentOptions,
            'filter' => $request->all('activity', 'activity_type', 'search', 'activity', 'district', 'tehsil', 'frequency', 'assigned', 'performed', 'expired')
        ]);
    }

    public function getActivitiesCalendar(Request $request): array
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $activities = TehsilActivity::query();
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }

        $activities = $activities
            ->when($request->search ?? null, function ($query, $search) use ($currentUser) {
                $query->whereHas('activity', function ($activity) use ($search) {
                    $activity->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->active()->where('id', $district);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->active()->where('id', $tehsil);
                });
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                $query->whereHas('activity', function ($activityQuery) use ($activity) {
                    $activityQuery->where('id', (integer)$activity);
                });
            })
            ->when($request->frequency ?? null, function ($query, $frequency) {
                $query->whereHas('frequency', function ($frequencyQuery) use ($frequency) {
                    $frequencyQuery->where('id', $frequency);
                });
            });
        if ($request->assignment == 'performed') {
            $activities = $activities->assigned()->performed();
        } else if ($request->assignment == 'assigned') {
            $activities = $activities->where('is_assigned', true);
        } else if ($request->assignment == 'expired') {
            $activities = $activities->unperformed()->inactive();
        } else {

        }
        if ($request->district != '') {
            $districtIds = $districtIds->where('id', $request->district)->pluck('districts.id');
        } else {
            if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
                $districtIds = $districtIds->pluck('districts.id');
            } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
                $districtIds = $currentUser->all_districts()->pluck('districts.id');
            } else {
                $districtIds = false;
            }
        }

        if ($request->tehsil != '') {
            $tehsil_ids = Tehsil::where('id', $request->tehsil)->pluck('tehsils.id');
            $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
        } else {
            if ($districtIds == false) {
                $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
                $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
            } else {
                $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($districtIds) {
                    $districtQuery->whereIn('id', $districtIds);
                });
            }
        }

        $activities = $activities->orderBy('id', 'DESC');
        $from_date = $request->has('start') ? Carbon::parse($request->start)->format('Y-m-d') : Carbon::now()->startOfMonth()->format('Y-m-d');
        $to_date = $request->has('end') ? Carbon::parse($request->end)->format('Y-m-d') : Carbon::now()->endOfMonth()->format('Y-m-d');
        $activities = $activities->where(function ($query) use ($from_date, $to_date) {
            $query->whereBetween('from_date', [$from_date, $to_date])->orWhereBetween('to_date', [$from_date, $to_date]);
        })->get();

        return [
            'data' => CalendarActivitiesResource::collection($activities),
            'filter' => $request->all('search', 'activity', 'district', 'tehsil', 'frequency', 'from_date', 'to_date', 'assigned', 'performed', 'expired', 'page_length', 'grouped')
        ];
    }

    public function getAdvocacyCalendar(Request $request): \Inertia\Response
    {
        $request->merge(['search' => '']);
        $request->merge(['activity' => '']);
        $request->merge(['tehsil' => '']);
        $request->merge(['frequency' => '']);
        $request->merge(['assignment' => '']);
        $request->merge(['activity_type' => '']);
        $assignmentOptions = [
            [
                "id" => 'assigned',
                "text" => "Assigned",
                "selected" => false
            ], [
                "id" => 'performed',
                "text" => 'Performed',
                "selected" => false
            ], [
                "id" => 'expired',
                "text" => "Expired",
                "selected" => false
            ],
        ];
        return Inertia::render('AdvocacyV2/Schedules/Calendar', [
            'activitiesOptions' => makeSelect2DropdownOptions(new Activity()),
            'activity_type_options' => get_activity_type_dropdown_options(),
            'assignmentOptions' => $assignmentOptions,
            'filter' => $request->all('activity', 'activity_type', 'search', 'activity', 'district', 'tehsil', 'assigned', 'performed', 'expired')
        ]);
    }

    public function getAdvocacyActivitiesCalendar(Request $request): array
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $activities = TehsilActivity::query();
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }

        $activities = $activities
            ->when($request->search ?? null, function ($query, $search) use ($currentUser) {
                $query->whereHas('activity', function ($activity) use ($search) {
                    $activity->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->active()->where('id', $district);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->active()->where('id', $tehsil);
                });
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                $query->whereHas('activity', function ($activityQuery) use ($activity) {
                    $activityQuery->where('id', (integer)$activity);
                });
            });
        if ($request->assignment == 'performed') {
            $activities = $activities->assigned()->performed();
        } else if ($request->assignment == 'assigned') {
            $activities = $activities->where('is_assigned', true);
        } else if ($request->assignment == 'expired') {
            $activities = $activities->unperformed()->inactive();
        } else {

        }
        if ($request->district != '') {
            $districtIds = $districtIds->where('id', $request->district)->pluck('districts.id');
        } else {
            if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
                $districtIds = $districtIds->pluck('districts.id');
            } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
                $districtIds = $currentUser->all_districts()->pluck('districts.id');
            } else {
                $districtIds = false;
            }
        }

        if ($request->tehsil != '') {
            $tehsil_ids = Tehsil::where('id', $request->tehsil)->pluck('tehsils.id');
            $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
        } else {
            if ($districtIds == false) {
                $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
                $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
            } else {
                $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($districtIds) {
                    $districtQuery->whereIn('id', $districtIds);
                });
            }
        }

        $activities = $activities->orderBy('id', 'DESC');
        $from_date = $request->has('start') ? Carbon::parse($request->start)->format('Y-m-d') : Carbon::now()->startOfMonth()->format('Y-m-d');
        $to_date = $request->has('end') ? Carbon::parse($request->end)->format('Y-m-d') : Carbon::now()->endOfMonth()->format('Y-m-d');
        $activities = $activities->where(function ($query) use ($from_date, $to_date) {
            $query->whereBetween('from_date', [$from_date, $to_date])->orWhereBetween('to_date', [$from_date, $to_date]);
        })->get();

        return [
            'data' => CalendarActivitiesResource::collection($activities),
            'filter' => $request->all('search', 'activity', 'district', 'tehsil', 'frequency', 'from_date', 'to_date', 'assigned', 'performed', 'expired', 'page_length', 'grouped')
        ];
    }
}

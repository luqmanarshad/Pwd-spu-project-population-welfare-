<?php

namespace App\Http\Controllers\AdvocacyV2;

use App\Http\Controllers\Controller;
use App\Models\AdvocacyV2\Activity;
use App\Models\AdvocacyV2\ActivityField;
use App\Models\AdvocacyV2\DistrictActivity;
use App\Models\AdvocacyV2\Frequency;
use App\Models\AdvocacyV2\TehsilActivity;
use App\Models\AdvocacyV2\UserActivity;
use App\Models\District;
use App\Models\Tehsil;
use App\Models\User;
use App\Notifications\AdvocacyV2\AssignActivityNotification;
use App\Notifications\AdvocacyV2\NewScheduleNotification;
use App\Notifications\AdvocacyV2\PerformActivityNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SchedulingController extends Controller
{
    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|view advocacy schedule|perform advocacy activity|assign advocacy activity to tehsils', ['only' => ['index']]);
        $this->middleware('role_or_permission:super admin|create advocacy schedule', ['only' => ['create', 'store']]);
        $this->middleware('role_or_permission:super admin|assign advocacy activity to tehsils', ['only' => ['assignSchedules', 'edit', 'update']]);
        $this->middleware('role_or_permission:super admin|delete advocacy schedule', ['only' => ['destroy']]);
        $this->middleware('role_or_permission:super admin|assign advocacy activity fields', ['only' => ['activityFields', 'assignActivityFields']]);
        $this->middleware('role_or_permission:super admin|create advocacy activity fields', ['only' => ['createActivityFields', 'storeActivityFields', 'updateActivityField', 'deleteActivityField']]);
        $this->middleware('role_or_permission:super admin|view advocacy activity|perform advocacy activity', ['only' => ['showScheduleActivity']]);
        $this->middleware('role_or_permission:super admin|perform advocacy activity', ['only' => ['createScheduleActivity', 'storeScheduleActivity']]);
        $this->middleware('permission:perform advocacy unscheduled activities', ['only' => ['unScheduleActivities', 'storeUnScheduleActivities']]);
        $this->middleware('permission:view advocacy dashboard', ['only' => ['dashboard']]);
    }

    public function dashboard(Request $request): Response
    {
        $from_date = false;
        $to_date = false;
        if ($request->from_date != '' && $request->to_date != '') {
            if ($request->from_date != '') {
                $from_date = Carbon::make($request->input('from_date'))->format('Y-m-d');
            }
            if ($request->to_date != '') {
                $to_date = Carbon::make($request->input('to_date'))->format('Y-m-d');
            }
        }
        $user = auth()->user();
        $district_ids = [];
        $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
        $cards = [];
        $total_schedules = DistrictActivity::query()->scheduled();
        if ($from_date != false && $to_date != false) {
            $total_schedules = $total_schedules->where(function ($dateQuery) use ($from_date, $to_date) {
                $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                    ->orWhereBetween('to_date', [$from_date, $to_date])
                    ->orWhere(function ($query) use ($from_date, $to_date) {
                        return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                    });
            });
        }
//        if ($user->hasRole('super admin') || $user->hasRole('DG')) {
        if ($user->hasAnyRole(User::$adminLevelRoles)) {
            $total_schedules = $total_schedules->count();
            $cards = [
                'total_users' => [
                    'title' => 'Users',
                    'label' => 'Users',
                    'hover' => 'Total Registered Users',
                    'icon' => 'user-group-icon',
                    'iconClrClass' => 'text-green-600',
                    'textClrClass' => 'text-green-200',
                    'link' => route('advocacy-v2.users.index'),
                    'count' => 0,
                    'cardBgClass' => 'from-teal-400 to-green-500',
                ],
                'total_unscheduled_activites' => [
                    'title' => 'Regular Activities',
                    'label' => 'Regular Activities',
                    'hover' => 'Total Regular Activities.',
                    'icon' => card_colors()['unscheduled']['icon'],
                    'iconClrClass' => card_colors()['unscheduled']['icon_color'],
                    'textClrClass' => card_colors()['unscheduled']['text_color'],
                    'link' => route('reports.activities.all', injectDatesParameterInArray(['activity_type' => 'unscheduled'], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['unscheduled']['card_color']
                ],
                'total_activites' => [
//                    'title' => 'Scheduled In ' . $total_schedules . ' Activities',
                    'title' => 'Scheduled Activities',
                    'label' => 'Scheduled Activities',
                    'hover' => 'Total Scheduled Activities.',
                    'icon' => card_colors()['scheduled']['icon'],
                    'iconClrClass' => card_colors()['scheduled']['icon_color'],
                    'textClrClass' => card_colors()['scheduled']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['scheduled']['card_color']
                ],
                'total_unassigned_activities' => [
//                    'title' => 'Unassigned In ' . $total_schedules . ' Activities',
                    'title' => 'Unassigned Activities',
                    'label' => 'Unassigned Activities',
                    'hover' => 'Total Unassigned Activities',
                    'icon' => card_colors()['unassigned']['icon'],
                    'iconClrClass' => card_colors()['unassigned']['icon_color'],
                    'textClrClass' => card_colors()['unassigned']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'assigned' => false], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['unassigned']['card_color']
                ],
                'total_performed_activities' => [
//                    'title' => 'Performed In ' . $total_schedules . ' Activities',
                    'title' => 'Performed Activities',
                    'label' => 'Performed Activities',
                    'hover' => 'Total Performed Activities',
                    'icon' => card_colors()['performed']['icon'],
                    'iconClrClass' => card_colors()['performed']['icon_color'],
                    'textClrClass' => card_colors()['performed']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'performed' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['performed']['card_color']
                ],
                'total_pending_activities' => [
//                    'title' => 'Pending In ' . $total_schedules . ' Activities',
                    'title' => 'Pending Activities',
                    'label' => 'Pending Activities',
                    'hover' => 'Total Pending Activities Which Are Assigned But Not Performed Yet.',
                    'icon' => card_colors()['pending']['icon'],
                    'iconClrClass' => card_colors()['pending']['icon_color'],
                    'textClrClass' => card_colors()['pending']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'performed' => false], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['pending']['card_color']
                ],
                'total_expired_activities' => [
                    'title' => 'Unperformed Activities',
                    'label' => 'Unperformed Activities',
                    'hover' => 'Total Unperformed Activities Which Are Assigned But Not Performed In The Give Time Frame.',
                    'icon' => card_colors()['expired']['icon'],
                    'iconClrClass' => card_colors()['expired']['icon_color'],
                    'textClrClass' => card_colors()['expired']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'expired' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['expired']['card_color']
                ],
            ];
        } else if ($user->hasAnyRole(User::$districtLevelRoles)) {
            $district_ids = $user->all_districts()->pluck('districts.id')->toArray();
            $total_schedules = $total_schedules->whereIn('district_id', $district_ids);
            $cards = [
                'dpwo_total_scheduled_activities' => [
                    'title' => 'Scheduled Activities',
                    'label' => 'Scheduled Activities',
                    'hover' => 'Total Scheduled Activities',
                    'icon' => card_colors()['scheduled']['icon'],
                    'iconClrClass' => card_colors()['scheduled']['icon_color'],
                    'textClrClass' => card_colors()['scheduled']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['scheduled']['card_color'],
                ],
                'dpwo_total_unscheduled_activities' => [
                    'title' => 'Regular Activities',
                    'label' => 'Regular Activities',
                    'hover' => 'Total Regular Activities',
                    'icon' => card_colors()['unscheduled']['icon'],
                    'iconClrClass' => card_colors()['unscheduled']['icon_color'],
                    'textClrClass' => card_colors()['unscheduled']['text_color'],
                    'link' => route('reports.activities.all', injectDatesParameterInArray(['activity_type' => 'unscheduled'], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['unscheduled']['card_color'],
                ],
                'dpwo_total_assigned_activities' => [
                    'title' => 'Assigned Activities',
                    'label' => 'Assigned Activities',
                    'hover' => 'Total Assigned Activities',
                    'icon' => card_colors()['assigned']['icon'],
                    'iconClrClass' => card_colors()['assigned']['icon_color'],
                    'textClrClass' => card_colors()['assigned']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dpwo', 'assigned' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['assigned']['card_color']
                ],
                'dpwo_unassigned_activities' => [
                    'title' => 'Unassigned Activities',
                    'label' => 'Unassigned Activities',
                    'hover' => 'Total Activities Which Are Scheduled But Not Assigned Yet.',
                    'icon' => card_colors()['unassigned']['icon'],
                    'iconClrClass' => card_colors()['unassigned']['icon_color'],
                    'textClrClass' => card_colors()['unassigned']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dpwo', 'assigned' => false], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['unassigned']['card_color']
                ],
                'dpwo_total_performed_activities' => [
                    'title' => 'Performed Activities',
                    'label' => 'Performed Activities',
                    'hover' => 'Total Performed Activities By Tehsils',
                    'icon' => card_colors()['performed']['icon'],
                    'iconClrClass' => card_colors()['performed']['icon_color'],
                    'textClrClass' => card_colors()['performed']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dpwo', 'performed' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['performed']['card_color']
                ],
                'dpwo_total_pending_activities' => [
                    'title' => 'Pending Activities',
                    'label' => 'Pending Activities',
                    'hover' => 'Total Pending Activities Which Are Assigned But Not Performed Yet.',
                    'icon' => card_colors()['pending']['icon'],
                    'iconClrClass' => card_colors()['pending']['icon_color'],
                    'textClrClass' => card_colors()['pending']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dpwo', 'performed' => false], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['pending']['card_color']
                ],
                'dpwo_total_expired_activities' => [
                    'title' => 'Unperformed Activities',
                    'label' => 'Unperformed Activities',
                    'hover' => 'Total Unperformed Activities Which Are Assigned But Not Performed In The Give Time Frame.',
                    'icon' => card_colors()['expired']['icon'],
                    'iconClrClass' => card_colors()['expired']['icon_color'],
                    'textClrClass' => card_colors()['expired']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'expired' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['expired']['card_color']
                ],
            ];
        } else if ($user->hasAnyRole(User::$tehsilLevelRoles)) {
            $total_schedules = $total_schedules->whereHas('tehsil_activities', function ($tehsil_activity) use ($tehsil_ids) {
                $tehsil_activity->whereIn('tehsil_id', $tehsil_ids);
            });
            $cards = [
                'tpwo_total_assigned_activities' => [
                    'title' => 'Total Activities',
                    'label' => 'Total Activities',
                    'hover' => 'Total Assigned Activities',
                    'icon' => card_colors()['assigned']['icon'],
                    'iconClrClass' => card_colors()['assigned']['icon_color'],
                    'textClrClass' => card_colors()['assigned']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'tpwo'], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['assigned']['card_color'],
                ],
                'tpwo_total_unscheduled_activities' => [
                    'title' => 'Regular Activities',
                    'label' => 'Regular Activities',
                    'hover' => 'Regular Activities',
                    'icon' => card_colors()['unscheduled']['icon'],
                    'iconClrClass' => card_colors()['unscheduled']['icon_color'],
                    'textClrClass' => card_colors()['unscheduled']['text_color'],
                    'link' => route('reports.activities.all', injectDatesParameterInArray(['activity_type' => 'unscheduled'], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['assigned']['card_color'],
                ],
                'tpwo_total_performed_activities' => [
                    'title' => 'Performed Activities',
                    'label' => 'Performed Activities',
                    'hover' => 'Total Performed Activities',
                    'icon' => card_colors()['performed']['icon'],
                    'iconClrClass' => card_colors()['performed']['icon_color'],
                    'textClrClass' => card_colors()['performed']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'tpwo', 'performed' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['performed']['card_color']
                ],
                'tpwo_total_pending_activities' => [
                    'title' => 'Pending Activities',
                    'label' => 'Pending Activities',
                    'hover' => 'Total Pending Activities',
                    'icon' => card_colors()['pending']['icon'],
                    'iconClrClass' => card_colors()['pending']['icon_color'],
                    'textClrClass' => card_colors()['pending']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'tpwo', 'performed' => false], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['pending']['card_color']
                ],
                'tpwo_total_expired_activities' => [
                    'title' => 'Unperformed Activities',
                    'label' => 'Unperformed Activities',
                    'hover' => 'Total Unperformed Activities Which Are Assigned But Not Performed In The Give Time Frame.',
                    'icon' => card_colors()['expired']['icon'],
                    'iconClrClass' => card_colors()['expired']['icon_color'],
                    'textClrClass' => card_colors()['expired']['text_color'],
                    'link' => route('advocacy-v2.schedules.index', injectDatesParameterInArray(['by' => 'dg', 'expired' => true], ['from_date' => $from_date, 'to_date' => $to_date])),
                    'count' => 0,
                    'cardBgClass' => card_colors()['expired']['card_color']
                ],
            ];
        }
        foreach ($cards as $key => $card) {
            switch ($key) {
                case 'total_users':
                    $cards['total_users']['count'] = User::Role(User::$allSpuRoles)->withTrashed()->noadmin()->count();
                    break;
                case 'total_unscheduled_activites':
                    $total_unscheduled_activites = UserActivity::unscheduled();
                    if ($from_date != false && $to_date != false) {
                        $total_unscheduled_activites = $total_unscheduled_activites->whereHas('tehsil_activity', function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('performed_at', [$from_date, $to_date]);
                        });
                    }

                    $cards['total_unscheduled_activites']['count'] = $total_unscheduled_activites->count();
                    break;
                case 'total_activites':
                    $total_activites = TehsilActivity::scheduled();
                    if ($from_date != false && $to_date != false) {
                        $total_activites = $total_activites->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['total_activites']['count'] = $total_activites->count();
                    break;
                case 'total_unassigned_activities':
                    $total_unassigned_activities = TehsilActivity::scheduled()->unassigned();
                    if ($from_date != false && $to_date != false) {
                        $total_unassigned_activities = $total_unassigned_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['total_unassigned_activities']['count'] = $total_unassigned_activities->count();
                    break;
                case 'total_performed_activities':
                    /*$cards['total_performed_activities']['count'] = UserActivity::whereHas('tehsil_activity', function ($query) {
                        $query->scheduled()
                            ->assigned()
                            ->performed();
                    })->count();*/
                    $total_performed_activities = TehsilActivity::scheduled()
                        ->assigned()
                        ->performed();
                    if ($from_date != false && $to_date != false) {
                        $total_performed_activities = $total_performed_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['total_performed_activities']['count'] = $total_performed_activities->count();
                    break;
                case 'total_pending_activities':
                    $total_pending_activities = TehsilActivity::active()
                        ->scheduled()
                        ->assigned();
                    if ($from_date != false && $to_date != false) {
                        $total_pending_activities = $total_pending_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['total_pending_activities']['count'] = $total_pending_activities->count();
                    break;
                case 'total_expired_activities':
                    $total_expired_activities = TehsilActivity::inactive()
                        ->scheduled()
                        ->assigned()
                        ->unPerformed();
                    if ($from_date != false && $to_date != false) {
                        $total_expired_activities = $total_expired_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['total_expired_activities']['count'] = $total_expired_activities->count();
                    break;
                case 'dpwo_total_scheduled_activities':
                    if (count($district_ids) > 0) {
                        $dpwo_total_scheduled_activities = TehsilActivity::scheduled()
                            ->whereHas('district_activity', function ($query) use ($district_ids) {
                                return $query->whereIn('district_id', $district_ids);
                            });
                        if ($from_date != false && $to_date != false) {
                            $dpwo_total_scheduled_activities = $dpwo_total_scheduled_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                                $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                    ->orWhereBetween('to_date', [$from_date, $to_date])
                                    ->orWhere(function ($query) use ($from_date, $to_date) {
                                        return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                    });
                            });
                        }
                        $cards['dpwo_total_scheduled_activities']['count'] = $dpwo_total_scheduled_activities->count();
                    }
                    break;
                case 'dpwo_total_unscheduled_activities':
                    if (count($district_ids) > 0) {
                        $dpwo_total_unscheduled_activities = TehsilActivity::unscheduled()
                            ->whereHas('district_activity', function ($query) use ($district_ids) {
                                return $query->whereIn('district_id', $district_ids);
                            });
                        if ($from_date != false && $to_date != false) {
                            $dpwo_total_unscheduled_activities = $dpwo_total_unscheduled_activities->whereBetween('performed_at', [$from_date, $to_date]);
                        }
                        $cards['dpwo_total_unscheduled_activities']['count'] = $dpwo_total_unscheduled_activities->count();
                    }
                    break;
                case 'dpwo_total_assigned_activities':
                    if (count($district_ids) > 0) {
                        $dpwo_total_assigned_activities = TehsilActivity::scheduled()
                            ->assigned()
                            ->whereHas('district_activity', function ($query) use ($district_ids) {
                                return $query->whereIn('district_id', $district_ids);
                            });
                        if ($from_date != false && $to_date != false) {
                            $dpwo_total_assigned_activities = $dpwo_total_assigned_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                                $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                    ->orWhereBetween('to_date', [$from_date, $to_date])
                                    ->orWhere(function ($query) use ($from_date, $to_date) {
                                        return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                    });
                            });
                        }
                        $cards['dpwo_total_assigned_activities']['count'] = $dpwo_total_assigned_activities->count();
                    }
                    break;
                case 'dpwo_unassigned_activities':
                    $dpwo_unassigned_activities = TehsilActivity::scheduled()
                        ->unassigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    if ($from_date != false && $to_date != false) {
                        $dpwo_unassigned_activities = $dpwo_unassigned_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['dpwo_unassigned_activities']['count'] = $dpwo_unassigned_activities->count();
//                    $cards['dpwo_unassigned_activities']['title'] = 'Unassigned In ' . $dpwo_unassigned_activities_from . ' Activities';
                    break;
                case 'dpwo_total_performed_activities':
                    /*$dpwo_total_performed_activities = UserActivity::whereHas('tehsil_activity.district_activity', function ($query) use ($district_ids) {
                        $query->whereIn('district_id', $district_ids);
                    })->whereHas('tehsil_activity', function ($query) {
                        $query->scheduled()->assigned()->performed();
                    });*/
                    $dpwo_total_performed_activities = TehsilActivity::whereHas('district_activity', function ($query) use ($district_ids) {
                        $query->whereIn('district_id', $district_ids);
                    })->scheduled()
                        ->assigned()
                        ->performed();
                    if ($from_date != false && $to_date != false) {
                        $dpwo_total_performed_activities = $dpwo_total_performed_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['dpwo_total_performed_activities']['count'] = $dpwo_total_performed_activities->count();
                    break;
                case 'dpwo_total_pending_activities':
                    $dpwo_total_pending_activities = TehsilActivity::active()
                        ->scheduled()
                        ->assigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    if ($from_date != false && $to_date != false) {
                        $dpwo_total_pending_activities = $dpwo_total_pending_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['dpwo_total_pending_activities']['count'] = $dpwo_total_pending_activities->count();
                    break;
                case 'dpwo_total_expired_activities':
                    $dpwo_total_expired_activities = TehsilActivity::inactive()
                        ->scheduled()
                        ->assigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    if ($from_date != false && $to_date != false) {
                        $dpwo_total_expired_activities = $dpwo_total_expired_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['dpwo_total_expired_activities']['count'] = $dpwo_total_expired_activities->count();
                    break;
                case 'tpwo_total_assigned_activities':
                    if (count($tehsil_ids) > 0) {
                        $tpwo_total_assigned_activities = TehsilActivity::scheduled()
                            ->assigned()
                            ->whereIn('tehsil_id', $tehsil_ids);
                        if ($from_date != false && $to_date != false) {
                            $tpwo_total_assigned_activities = $tpwo_total_assigned_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                                $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                    ->orWhereBetween('to_date', [$from_date, $to_date])
                                    ->orWhere(function ($query) use ($from_date, $to_date) {
                                        return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                    });
                            });
                        }
                        $cards['tpwo_total_assigned_activities']['count'] = $tpwo_total_assigned_activities->count();
                    }
                    break;
                case 'tpwo_total_performed_activities':
                    $tpwo_total_performed_activities = TehsilActivity::whereIn('tehsil_id', $tehsil_ids)
                        ->scheduled()
                        ->assigned()
                        ->performed();
                    if ($from_date != false && $to_date != false) {
                        $tpwo_total_performed_activities = $tpwo_total_performed_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['tpwo_total_performed_activities']['count'] = $tpwo_total_performed_activities->count();
                    break;
                case 'tpwo_total_pending_activities':
                    $tpwo_total_pending_activities = TehsilActivity::active()
                        ->scheduled()
                        ->assigned()
                        ->whereIn('tehsil_id', $tehsil_ids);
                    if ($from_date != false && $to_date != false) {
                        $tpwo_total_pending_activities = $tpwo_total_pending_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['tpwo_total_pending_activities']['count'] = $tpwo_total_pending_activities->count();
                    break;
                case 'tpwo_total_expired_activities':
                    $tpwo_total_expired_activities = TehsilActivity::inactive()
                        ->scheduled()
                        ->assigned()
                        ->unPerformed()
                        ->whereIn('tehsil_id', $tehsil_ids);
                    if ($from_date != false && $to_date != false) {
                        $tpwo_total_expired_activities = $tpwo_total_expired_activities->where(function ($dateQuery) use ($from_date, $to_date) {
                            $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                                ->orWhereBetween('to_date', [$from_date, $to_date])
                                ->orWhere(function ($query) use ($from_date, $to_date) {
                                    return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                                });
                        });
                    }
                    $cards['tpwo_total_expired_activities']['count'] = $tpwo_total_expired_activities->count();
                    break;
                default:
            }
        }
        $chart = [];
        $chart['pie']['data'] = [];
        $chart['barChartData'] = [];
        $chart['barChartData']['chartOptions'] = [];
        $chart['lineChartData'] = [];
        foreach ($cards as $cardIndex => $card) {
            if ($cardIndex != 'total_users' && $cardIndex != 'total_activites') {
                $chart['pie']['data'][] = [
                    'name' => $card['title'],
                    'y' => $card['count'],
                    'label' => $card['label']
                ];
            }
        }
        $recentActivities = UserActivity::with(['tehsil_activity']);
        if ($user->hasAnyRole(User::$adminLevelRoles)) {
            $districts = District::active()->with('advocacy_v2_all_performed_tehsil_activities')
                ->withCount([
                    'advocacy_v2_all_performed_tehsil_activities',
                    'advocacy_v2_all_performed_tehsil_activities as all_performed_tehsil_activities_count' => function ($query) use ($from_date, $to_date) {
                        if ($from_date != false && $to_date != false) {
                            $query->whereBetween('performed_at', [$from_date, $to_date]);
                        } else {
                            return $query;
                        }
                    }])
                ->orderBy('all_performed_tehsil_activities_count', 'DESC')
                ->limit(10)
                ->get();

            $chart['bar']['drilldown'] = [
                'activeAxisLabelStyle' => [
                    'color' => '#ef9722'
                ],
                'activeDataLabelStyle' => [
                    'color' => '#ef9722'
                ],
                'breadcrumbs' => [
                    'position' => [
                        'align' => 'right'
                    ]
                ],
                'series' => []
            ];
            foreach ($districts as $di => $district) {
                $chart['bar']['labels'][] = $district->name;
                $tehsilsCount = $district->all_performed_tehsil_activities_count;
                $chart['bar']['data'][] = [
                    'name' => $district->name,
                    'y' => $tehsilsCount,
                    'drilldown' => ($tehsilsCount > 0 ? $district->name : null),
                ];
                $chart['bar']['drilldown']['series'][$di] = [
                    'name' => $district->name,
                    'id' => $district->name,
                    'data' => []
                ];
                $tehsils = $district->tehsils()->with('advocacy_v2_activities')->get();
                if ($tehsils->count() > 0) {
                    foreach ($tehsils as $ti => $tehsil) {
                        $chart['bar']['drilldown']['series'][$di]['data'][$ti] = [
                            $tehsil->name,
                            $this->getTehsilDrilldownCount($tehsil, $from_date, $to_date)
                        ];
                    }
                }
            }
            $chart['barChartData'] = getBarChart($chart['bar'], 'Top 10 District Activities Wise', 'Click District/Column to view its tehsil activities wise.', 1, $chart['bar']['drilldown']);
            $chart['lineChartData'] = AdvocacyV2lineBarChart($user, false, '', $from_date, $to_date);
        } else if ($user->hasAnyRole(User::$districtLevelRoles)) {
            $recentActivities = $recentActivities->whereHas('tehsil_activity.district_activity', function ($query) use ($district_ids) {
                $query->whereIn('district_id', $district_ids);
            });
            $tehsils = Tehsil::active()->whereIn('district_id', $district_ids)->with('advocacy_v2_activities')->get();
            foreach ($tehsils as $tehsil) {
                $chart['bar']['labels'][] = $tehsil->name;
                $chart['bar']['data'][] = $tehsil->advocacy_v2_activities()->active()->wherePivot('is_assigned', 1)->wherePivot('is_performed', 1)->count();
            }
            $chart['barChartData'] = getBarChart($chart['bar'], 'Tehsil&nbsp;Activities&nbsp;Wise', '', 1);
            $chart['lineChartData'] = AdvocacyV2lineBarChart($user);
        } else if ($user->hasAnyRole(User::$tehsilLevelRoles)) {
//            $recentActivities = $recentActivities->whereIn('tehsil_id', $tehsil_ids);
            $recentActivities->whereHas('tehsil_activity', function ($query) use ($tehsil_ids) {
                $query->whereIn('tehsil_id', $tehsil_ids);
            });
            foreach ($cards as $key => $card) {
                $chart['bar']['labels'][] = $card['title'];
                $chart['bar']['data'][] = $card['count'];
            }
            $chart['barChartData'] = getBarChart($chart['bar'], 'Tehsil&nbsp;Activities&nbsp;Wise', '', 1);
            $chart['lineChartData'] = [];
        }
        $recentActivities = $recentActivities->latest()->take(3)->get();
        $filteredActivities = [];
        $image_links = [];
        foreach ($recentActivities as $recentActivity) {
//            $images = $recentActivity->activity->fields()->select('activity_fields.id', 'title', 'name', 'type', 'default_value')->where('type', 'multi_images')->get();
            $images = @json_decode($recentActivity->multi_images);
            $json_OK = json_last_error() == JSON_ERROR_NONE;
            if ($json_OK) {
                foreach ($images as $image) {
                    if (nfs()) {
                        if (Storage::disk('nfs')->exists($image)) {
                            $image_links[] = Storage::disk('nfs')->url($image);
                        }
                    } else {
                        if (Storage::disk('public')->exists($image)) {
                            $image_links[] = Storage::disk('public')->url($image);
                        }
                    }
                }
            }
            $shortName = Str::limit($recentActivity->tehsil_activity->activity->name, 60);
            $tehsilActivity = $recentActivity->tehsil_activity;
            $districtActivity = $tehsilActivity->district_activity;
            $activity = $tehsilActivity->activity;
            $filteredActivities[] = [
                'id' => $recentActivity->id,
                'tehsil_activity_id' => $tehsilActivity->id,
                'district_activity_id' => $districtActivity->id,
                'name' => @$activity->name,
                'short_name' => $shortName,
                'display' => $shortName,
                'more_btn' => 'More',
                'name_count' => strlen($activity->name),
                'date' => @$recentActivity->created_at->format('d M, Y'),
                'time' => @$recentActivity->created_at->format('H:s A'),
                'district' => @$districtActivity->district->name,
                'tehsil' => @$tehsilActivity->tehsil->name
            ];
        }
        $medias = [];
        $medias['data'] = [];
        $seven_days_before_date = Carbon::now()->subDays(7)->format('Y-m-d');
        $inActiveDistricts = District::whereDoesntHave('advocacy_v2_tehsil_activities', function ($query) use ($seven_days_before_date, $from_date, $to_date) {
            if ($from_date != false && $to_date != false) {
                $query->whereBetween('performed_at', [$from_date, $to_date]);
            } else {
                $query->whereDate('performed_at', '>=', $seven_days_before_date);
            }
        })->get();
        list($districtsCoordinates, $mapOptions) = parse_xml_object_old_tehsils($request, 'advocacy_v2_activities', $from_date, $to_date);
        return Inertia::render('AdvocacyV2/Dashboard', [
            'total_schedules' => $total_schedules,
            'cards' => $cards,
            'chart' => $chart,
            'recentActivities' => $filteredActivities,
            'medias' => $medias,
            'districtsCoordinates' => $districtsCoordinates,
            'mapOptions' => $mapOptions,
            'inActiveDistricts' => $inActiveDistricts,
            'filter' => $request->all('from_date', 'to_date')
        ]);
    }

    public function getTehsilDrilldownCount($tehsil, $from_date, $to_date)
    {
        $query = TehsilActivity::performed()->where('tehsil_id', $tehsil->id);
        if ($from_date != false && $to_date != false) {
            $query->whereBetween('performed_at', [$from_date, $to_date]);
        }
        return $query->count();
    }

    public function index(Request $request): Response
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $table = DistrictActivity::query()->scheduled();
        if ($request->has('grouped')) {
            $grouped = (boolean)$request->grouped;
        } else {
            $grouped = false;
        }
        $showTehsil = false;
        $groupAble = ($currentUser->hasAnyRole(User::$adminLevelRoles) || $currentUser->hasAnyRole(User::$districtLevelSpuRoles));
//        if ($currentUser->hasRole('TPWO')) {
        if (!$grouped &&
            ($request->assigned == '1' ||
                $request->assigned == '0' ||
                $request->performed == '1' ||
                $currentUser->hasAnyRole(User::$adminLevelRoles) ||
                $currentUser->hasAnyRole(User::$districtLevelSpuRoles) ||
                $currentUser->hasAnyRole(User::$tehsilLevelSpuRoles) ||
                $currentUser->hasAnyRole(User::$ad_iec_user_roles)
            )) {
            $table = TehsilActivity::query()->scheduled();
            $showTehsil = true;
            $grouped = false;
        }
        $activities = $table
            ->when($request->search ?? null, function ($query, $search) use ($currentUser, $table) {
                $query->whereHas('activity', function ($activity) use ($search) {
                    $activity->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($request->district ?? null, function ($query, $district) use ($table) {
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('district', function ($districtQuery) use ($district) {
                        $districtQuery->active()->where('id', $district);
                    });
                } else {
                    $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                        $districtQuery->active()->where('id', $district);
                    });
                }
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) use ($table) {
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->active()->where('id', $tehsil);
                    });
                } else {
                    $query->whereHas('tehsil', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->active()->where('id', $tehsil);
                    });
                }
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                $query->whereHas('activity', function ($activityQuery) use ($activity) {
                    $activityQuery->where('id', $activity);
                });
            })
            ->when($request->frequency ?? null, function ($query, $frequency) {
                $query->whereHas('frequency', function ($frequencyQuery) use ($frequency) {
                    $frequencyQuery->where('id', $frequency);
                });
            })
            ->when($request->has('performed'), function ($query, $is_performed) use ($table, $request) {
                $is_performed = (boolean)$request->performed;
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_performed) {
                        if (!$is_performed) {
                            $tehsilActivityQuery->assigned()->where('is_expired', $is_performed);
                        } else {
                            $tehsilActivityQuery->assigned()->performed();
                        }
                    });
                } else {
                    if (!$is_performed) {
                        $query->assigned()->where('is_expired', $is_performed);
                    } else {
                        $query->assigned()->performed();
                    }

                }
            })
            ->when($request->has('assigned'), function ($query, $is_assigned) use ($table, $request) {
                $is_assigned = $request->assigned;
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_assigned) {
                        $tehsilActivityQuery->where('is_assigned', $is_assigned);
                    });
                } else {
                    $query->where('is_assigned', $is_assigned);
                }
            });

        if ($request->has('expired') && in_array($request->expired, [0, 1, '0', '1', false, true])) {
            if ((boolean)$request->expired) {
                $activities = $activities->unperformed()->inactive();
            }
        }
        $page_length = $request->has('page_length') ? $request->page_length : 10;
//        if ($currentUser->hasRole('super admin') || $currentUser->hasRole('DG')) {
        if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
            $districtIds = $districtIds->pluck('districts.id');
//        } elseif ($currentUser->hasRole('DPWO')) {
        } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles) || $currentUser->hasAnyRole(User::$ad_iec_user_roles)) {
            $districtIds = $currentUser->all_districts()->pluck('districts.id');
        } else {
            $districtIds = false;
        }
        if ($districtIds == false) {
            $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
            $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
        } else {
            if ($table->getModel() instanceof DistrictActivity) {
                $activities = $activities->whereIn('district_id', $districtIds);
            } else {
                $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($districtIds) {
                    $districtQuery->whereIn('id', $districtIds);
                });
            }
        }
        if ($request->has('from_date') && $request->has('to_date')) {
            $from_date = Carbon::make($request->input('from_date'))->format('Y-m-d');
            $to_date = Carbon::make($request->input('to_date'))->format('Y-m-d');
            $activities = $activities->where(function ($dateQuery) use ($from_date, $to_date) {
                $dateQuery->whereBetween('from_date', [$from_date, $to_date])
                    ->orWhereBetween('to_date', [$from_date, $to_date])
                    ->orWhere(function ($query) use ($from_date, $to_date) {
                        return $query->whereDate('from_date', '<=', $from_date)->whereDate('to_date', '>=', $to_date);
                    });
            });
        }
        if ($currentUser->hasAnyRole(User::$ad_iec_user_roles)) {
            $userRoles = $currentUser->roles()->pluck('id')->toArray();
//            $user_districts = $currentUser->all_districts()->pluck('name')->toArray();
            $activities = $activities->whereHas('district_activity', function ($district_activity_query) use ($userRoles) {
                return $district_activity_query->whereHas('additionalAssignedRoles', function ($tehsil_activity_query) use ($userRoles) {
                    return $tehsil_activity_query->whereIn('roles.id', $userRoles);
                });
            })->whereNull('tehsil_id');
        }
        $activities = $activities
            ->orderBy('id', 'DESC')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($activity_frequency) => [
                'id' => $activity_frequency->id,
                'district_activity_id' => ($activity_frequency instanceof DistrictActivity) ? $activity_frequency->id : $activity_frequency->district_activity->id,
                'district' => isset($activity_frequency->district_id) ? $activity_frequency->district : ($activity_frequency->district_activity ? $activity_frequency->district_activity->district : ''),
                'tehsil' => isset($activity_frequency->tehsil_id) ? $activity_frequency->tehsil : '',
                'activity' => Activity::whereId($activity_frequency->activity_id)->first(),
                'frequency' => Frequency::whereId($activity_frequency->frequency_id)->first(),
                'from_date' => getAdvocacyScheduleDates($activity_frequency, 'from_date'),
                'to_date' => getAdvocacyScheduleDates($activity_frequency, 'to_date'),
                'is_expired' => isScheduleExpired($activity_frequency),
                'is_expired_for_assigning' => isScheduleExpired($activity_frequency, true),
                'description' => ($activity_frequency instanceof DistrictActivity) ? @$activity_frequency->description : @$activity_frequency->district_activity->description,
                'is_assigned' => $activity_frequency->is_assigned,
                'delete_schedule_endpoint' => auth()->user()->can('delete advocacy schedule') ? (($activity_frequency instanceof DistrictActivity) ? route('advocacy-v2.schedules.delete', $activity_frequency->id) : route('advocacy-v2.schedules.delete.tehsil', $activity_frequency->id)) : false,
                'performed_activities_count' => $this->getPerformedActivitiesCount($activity_frequency),
                'is_performed' => $this->getIsPerformedAttribute($activity_frequency),
                'current_user_can_perform_activity' => canPerformAdvocacyActivity($activity_frequency),
                'is_iec_user' => auth()->user()->hasAnyRole(User::$ad_iec_user_roles)
            ]);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['grouped' => (boolean)$grouped]);
        return Inertia::render('AdvocacyV2/Schedules/Index', [
            'activities' => $activities,
            'activitiesOptions' => makeSelect2DropdownOptions(new Activity()),
            'frequencyOptions' => makeSelect2DropdownOptions(new Frequency()),
            'showTehsil' => $showTehsil,
            'groupAble' => $groupAble,
            'filter' => $request->all('search', 'activity', 'district', 'tehsil', 'frequency', 'from_date', 'to_date', 'assigned', 'performed', 'expired', 'page_length', 'grouped')
        ]);
    }
    public function getPerformedActivitiesCount($activity_frequency)
    {
        if ($activity_frequency instanceof DistrictActivity) {
            $count = '';
        } else if (auth()->user()->hasAnyRole(User::$ad_iec_user_roles)) {
            $count = $activity_frequency->user_activities()->where('user_id', auth()->id())->count();
        } else {
            $count = $activity_frequency->user_activities()->count();
        }
        return $count;
    }

    public function getIsPerformedAttribute($activity)
    {
        if ($activity instanceof TehsilActivity) {
            $is_assigned = (boolean)$activity->is_performed;
        } else if ($activity instanceof DistrictActivity) {
            $tehsil_activities = $activity->tehsil_activities()->where('is_assigned', true)->where('is_performed', true)->get();
            $is_assigned = [];
            foreach ($tehsil_activities as $tehsil_activity) {
                $is_assigned[] = [
                    'id' => $tehsil_activity->id,
                    'tehsil' => @$tehsil_activity->tehsil->name,
                    'is_assigned' => (boolean)$tehsil_activity->is_assigned,
                    'is_performed' => (boolean)$tehsil_activity->is_performed,
                ];
            }
        }
        return $is_assigned;
    }

    public function assignSchedules(Request $request): Response
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $table = DistrictActivity::query()->scheduled();
        $showTehsil = false;
        $activities = $table
            ->when($request->search ?? null, function ($query, $search) use ($currentUser, $table) {
                $query->whereHas('activity', function ($activity) use ($search) {
                    $activity->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($request->district ?? null, function ($query, $district) use ($table) {
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('district', function ($districtQuery) use ($district) {
                        $districtQuery->where('id', $district);
                    });
                } else {
                    $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                        $districtQuery->where('id', $district);
                    });
                }
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) use ($table) {
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->where('id', $tehsil);
                    });
                } else {
                    $query->whereHas('district_activity.district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->where('id', $tehsil);
                    });
                }
            })
            ->when($request->frequency ?? null, function ($query, $frequency) {
                $query->whereHas('frequency', function ($frequencyQuery) use ($frequency) {
                    $frequencyQuery->where('name', 'like', '%' . $frequency . '%');
                });
            })
            ->when($request->has('performed'), function ($query, $is_performed) use ($table, $request) {
                $is_performed = (boolean)$request->performed;
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_performed) {
                        $tehsilActivityQuery->assigned()->where('is_performed', $is_performed);
                    });
                } else {
                    $query->assigned()->where('is_performed', $is_performed);
                }
            })
            ->when($request->has('assigned'), function ($query, $is_assigned) use ($table, $request) {
                $is_assigned = $request->assigned;
                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_assigned) {
                        $tehsilActivityQuery->where('is_assigned', $is_assigned);
                    });
                } else {
                    $query->where('is_assigned', $is_assigned);
                }
            })
            ->when($request->current_month ?? null, function ($query, $current_month) use ($table) {

                if ($table->getModel() instanceof DistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($current_month) {
                        $tehsilActivityQuery->currentMonth();
                    });
                } else {
                    $query->currentMonth();
                }
            });

        $page_length = $request->has('page_length') ? $request->page_length : 10;
//        if ($currentUser->hasRole('super admin') || $currentUser->hasRole('DG')) {
        if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
            $districtIds = $districtIds->pluck('districts.id');
//        } elseif ($currentUser->hasRole('DPWO')) {
        } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
            $districtIds = $currentUser->all_districts()->pluck('districts.id');
        } else {
            $districtIds = false;
        }
        if ($districtIds == false) {
            $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
            $activities = $activities->with('district_activity')->whereIn('tehsil_id', $tehsil_ids)->assigned();
        } else {
            if ($table->getModel() instanceof DistrictActivity) {
                $activities = $activities->whereIn('district_id', $districtIds);
            } else {
                $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($districtIds) {
                    $districtQuery->whereIn('id', $districtIds);
                });
            }
        }
        if ($request->has('from_date') && $request->has('to_date')) {
            $from_date = Carbon::make($request->input('from_date'))->format('Y-m-d');
            $to_date = Carbon::make($request->input('to_date'))->format('Y-m-d');
            $activities->whereDate('from_date', '>=', $from_date)
                ->whereDate('to_date', '<=', $to_date);
        }

        $activities = $activities
            ->orderBy('id', 'DESC')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($activity_frequency) => [
                'id' => $activity_frequency->id,
                'district_activity_id' => ($activity_frequency instanceof DistrictActivity) ? $activity_frequency->id : $activity_frequency->district_activity->id,
                'district' => isset($activity_frequency->district_id) ? $activity_frequency->district : ($activity_frequency->district_activity ? $activity_frequency->district_activity->district : ''),
                'tehsil' => isset($activity_frequency->tehsil_id) ? $activity_frequency->tehsil : '',
                'activity' => Activity::whereId($activity_frequency->activity_id)->first(),
                'frequency' => Frequency::whereId($activity_frequency->frequency_id)->first(),
                'from_date' => ($activity_frequency instanceof DistrictActivity) ? date('M d,Y', strtotime($activity_frequency->from_date)) : date('M d,Y', strtotime($activity_frequency->district_activity->from_date)),
                'to_date' => ($activity_frequency instanceof DistrictActivity) ? date('M d,Y', strtotime($activity_frequency->to_date)) : date('M d,Y', strtotime($activity_frequency->district_activity->to_date)),
                'is_assigned' => $activity_frequency->is_assigned,
                'is_performed' => $this->getIsPerformedAttribute($activity_frequency)
            ]);
        return Inertia::render('AdvocacyV2/Schedules/Index', [
            'activities' => $activities,
            'showTehsil' => $showTehsil,
            'filter' => [
                $request->only('search'),
                'page_length' => $page_length,
                $request->only('role')
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Validator::make($request->all(), [
            'districts' => ['required', 'exists:districts,id'],
            'role' => ['required', 'exists:roles,id'],
            'additional_roles' => ['nullable', 'exists:roles,id'],
            'activity_frequency' => ['required', 'array'],
//            'fromToDate.start' => ['required', 'date', 'after:today'],
            'fromToDate.start' => ['required', 'date'],
//            'fromToDate.end' => ['required', 'date', 'after:today']
            'fromToDate.end' => ['required', 'date']
        ], [
            'fromToDate.start.required' => 'The from date field is required',
            'fromToDate.end.required' => ' & The to date field is required.',
            'activity_frequency.required' => 'The activity field is required..'
        ])->validate();

        $districts = District::active()->whereIn('id', $request->districts)->get();
        $data = [];
        foreach ($districts as $district) {
            foreach ($request->activity_frequency as $activity_frequency) {
                $districtData = [
                    'district_id' => $district->id,
                    'activity_id' => $activity_frequency['activity_id'],
                    'frequency_id' => $activity_frequency['frequency_id'],
                    'from_date' => date('Y-m-d', strtotime($request->get('fromToDate')['start'])),
                    'to_date' => date('Y-m-d', strtotime($request->get('fromToDate')['end'])),
                    'description' => $activity_frequency['description'] ?? '',
                    'is_assigned' => false,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ];
                $districtActivity = DistrictActivity::create($districtData);
                $districtTehsilData = [
                    'district_activity_id' => $districtActivity->id,
//                    'tehsil_id' => '',
                    'activity_id' => $districtActivity->activity_id,
                    'frequency_id' => $districtActivity->frequency_id,
                    'is_performed' => false,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ];
                TehsilActivity::create($districtTehsilData);
                if ($districtActivity) {
//                    Notification::send($district->users()->role('DPWO')->get(), new NewScheduleNotification($districtActivity));
                    Notification::send($district->users()->permission('assign advocacy activity to tehsils')->get(), new NewScheduleNotification($districtActivity, auth()->user()));
                    if ($request->has('additional_roles') && is_array($request->get('additional_roles')) && count($request->get('additional_roles')) > 0) {
                        foreach ($request->additional_roles as $additional_role) {
                            $districtActivity->additionalAssignedRoles()->attach($additional_role);
                        }
                    }
                    foreach ($district->tehsils()->active()->get() as $tehsil) {
                        $tehsilData = [
                            'district_activity_id' => $districtActivity->id,
                            'tehsil_id' => $tehsil->id,
                            'activity_id' => $districtActivity->activity_id,
                            'frequency_id' => $districtActivity->frequency_id,
                            'is_performed' => false,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id()
                        ];
                        TehsilActivity::create($tehsilData);
                    }
                }
            }
        }
        return redirect()->back();
    }

    public function create(): Response
    {
        return Inertia::render('AdvocacyV2/Schedules/Create', [
//            'availableRoles' => getAllRoleOptions('DPWO'),
            'availableRoles' => getAllRoleOptions(User::$districtLevelSpuRoles),
            'availableRoleIds' => getAllRoleIds(User::$districtLevelSpuRoles),
            'availableAdditionalRoles' => getAllRoleOptions(User::$ad_iec_user_roles),
            'districtOptions' => makeSelect2DropdownOptions(new District),
            'frequencyOptions' => makeSelect2DropdownOptions(new Frequency),
            'activityOptions' => makeSelect2DropdownOptions(new Activity)
        ]);
    }

//assign_activity_to_tehsils

    public function edit(Request $request, $districtActivity_id): Response
    {
        $districtActivity = DistrictActivity::with('district', 'activity', 'frequency')
//            TODO:: Check this condition if need or not
            /*->whereHas('tehsil_activities', function($tehsil_activity){
                return $tehsil_activity->where('is_assigned', 0);
            })*/
            ->findOrFail($districtActivity_id);
        $this->authorize('assignActivity', $districtActivity);
        $backdate_scheduling = config('advocacy.backdate_scheduling');
        if (!$backdate_scheduling && isScheduleExpired($districtActivity, true)) {
            $component = 'AdvocacyV2/Schedules/ScheduleExpired';
            $data['activity'] = $districtActivity->activity;
            $data['from_date'] = date('M d,Y', strtotime($districtActivity->from_date));
            $data['to_date'] = date('M d,Y', strtotime($districtActivity->to_date));
            $data['current_date'] = date('M d,Y');
            $data['backlink'] = route('advocacy-v2.schedules.index', ['by' => strtolower(auth()->user()->getRoleNames()->first()), 'assigned' => false]);
            return Inertia::render($component, $data);
        }

        $component = 'AdvocacyV2/Schedules/Edit';
        $tehsilActivities = [];
        if ($districtActivity) {
            $tehsilActivities = $districtActivity
                ->tehsil_activities()
                ->whereNotNull('tehsil_id')
                ->paginate(100)
                ->withQueryString()
                ->through(fn($tehsil_activity) => [
                    'id' => $tehsil_activity->id,
                    'district_activity_id' => $tehsil_activity->district_activity_id,
                    'tehsil_activity_id' => $tehsil_activity->id,
                    'tehsil' => $tehsil_activity->tehsil,
                    'fromToDate' => [
                        'start' => $tehsil_activity->from_date != null ? $tehsil_activity->from_date->format('M d, Y') : '',
                        'end' => $tehsil_activity->to_date != null ? $tehsil_activity->to_date->format('M d, Y') : ''
                    ],
                    'loading' => false,
                    'errors' => '',
                    'is_assigned' => (boolean)$tehsil_activity->is_assigned,
                    'is_performed' => (boolean)$tehsil_activity->is_performed,
                    'performed_at' => (boolean)$tehsil_activity->performed_at,
                ])->items();
        }
        $data['districtActivity'] = $districtActivity;
        $data['tehsilActivities'] = $tehsilActivities;

        return Inertia::render($component, $data);
    }

    public function activityFields(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activities = Activity::active()->select('id', 'name', 'status', 'order')->with('fields')->get()->toArray();
        $filterActivities = [];
        $activityFields = makeSelect2DropdownOptions(new ActivityField());

        foreach ($activities as $key => $activity) {
            $activity['text'] = $activity['name'];
            $field_options = $activityFields;
            $rawFields = [];
            foreach ($field_options as $foKey => $field_option) {
                foreach ($activity['fields'] as $afKey => $field) {
                    if ($field_option['id'] == $field['id']) {
                        $field_options[$foKey]['selected'] = true;
                        $rawFields[] = $field['id'];
                    }
                }

            }
            $activity['fields'] = $rawFields;
            $activity['field_options'] = $field_options;
            $filterActivities[$key] = $activity;
        }
        $fields = ActivityField::active()
            ->when($request->search ?? null, function ($query, $search) {
                $query->where('name', 'LIKE', '%' . $search . '%')
                    ->orWhere('type', 'LIKE', '%' . $search . '%');
            })
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($activity_field) => [
                'id' => $activity_field->id,
                'title' => $activity_field->title,
                'name' => $activity_field->name,
                'type' => $activity_field->type,
                'is_required' => (boolean)$activity_field->is_required,
                'status' => (boolean)$activity_field->status,
                'options' => $activity_field->fieldOptions
            ]);
        $request->merge(['page_length' => $page_length]);
        return Inertia::render('AdvocacyV2/ActivityFields/Index', [
            'activityOptions' => $filterActivities,
            'fields' => $fields,
            'availableFieldTypes' => getAllAdvocacyV2ActivityFieldsOptions(),
            'filter' => $request->all('search', 'page_length')
        ]);
    }

    public function assignActivityFields(Activity $activity, Request $request): JsonResponse
    {
        $response = [
            'status' => true,
            'message' => 'Activity Updated',
        ];
        $code = 200;
        try {
            $activity->fields()->sync($request->all());
        } catch (\Exception $exception) {
            $response['status'] = false;
            $response['message'] = $exception->getMessage();
            $code = $exception->getCode();
        }
        return response()->json($response, $code);
    }

    public function createActivityFields()
    {
        $field_type_options = getAdvocacyV2ActivityFieldTypeOptionsForSelect2();
        return Inertia::render('AdvocacyV2/ActivityFields/Create', ['field_type_options' => $field_type_options]);
    }

    public function storeActivityFields(Request $request)
    {
        $rules = [
            'title' => ['required', 'unique:advocacy_v2_activity_fields,title'],
            'name' => ['required', 'string', 'unique:advocacy_v2_activity_fields,name', "max:25"],
            'default_value' => ['max:255'],
            'field_type' => ['required', 'in:' . implode(',', ActivityField::$availableFields)],
            'options' => ['sometimes', 'required_if:field_type,dropdown,checkbox,radio', 'array'],
            'options.*.option' => ['nullable', 'required_if:field_type,dropdown,checkbox,radio', 'string', 'max:255'],
            'options.*.value' => ['nullable', 'required_if:field_type,dropdown,checkbox,radio', 'string', 'max:255']
        ];
        $messages = [
            'options.*.option.required_if' => 'The option field is required when field type is checkbox or dropdown or radio.',
            'options.*.value.required_if' => 'The value field is required when field type is checkbox or dropdown or radio.'
        ];
        $validate_data = $request->validate($rules, $messages);
        if (Schema::hasColumn('advocacy_v2_user_activity', $validate_data['name'])) {
            session()->flash('flash.banner', $validate_data['name'] . ' already exists in the database please choose another name.');
            session()->flash('flash.bannerStyle', 'danger');
            $error = \Illuminate\Validation\ValidationException::withMessages([
                'name' => [$validate_data['name'] . ' already exists in the database please choose another name.'],
            ]);
            throw $error;
        }

        $is_required = false;
        if ($request->has('is_required')) {
            $is_required = $request->get('is_required');
        }
        $data = [
            'title' => $validate_data['title'],
            'name' => $validate_data['name'],
            'type' => $validate_data['field_type'],
            'is_required' => $is_required,
            'default_value' => $request->default_value,
        ];
        $activity_field = ActivityField::create($data);
        if ($activity_field) {
            if (in_array($request->field_type, ['dropdown', 'checkbox', 'radio'])) {
                $options = $request->options;
                foreach ($options as $option) {
                    $activity_field_options_data = [
                        'activity_field_id' => $activity_field->id,
                        'option' => $option['option'],
                        'value' => $option['value']
                    ];
                    $activity_field->fieldOptions()->create($activity_field_options_data);
                }
            }
            $type = 'string';
            $create_column = true;
            $try_counter = 0;
            while ($create_column) {
                try {
                    $length = 255;
                    if ($validate_data['field_type'] == 'date') {
                        $type = 'date';
                        $length = false;
                    } elseif ($validate_data['field_type'] == 'textarea' || $validate_data['field_type'] == 'multi_images') {
                        $type = 'text';
                        $length = false;
                    } elseif ($validate_data['field_type'] == 'integer') {
                        $type = 'integer';
                        $length = false;
                    }
                    Schema::table('advocacy_v2_user_activity', function (Blueprint $table) use ($length, $validate_data, $type, $try_counter) {
                        if ($length != false) {
                            if ($try_counter == 1 && $type == 'string') {
                                $type = 'text';
                                $table->{$type}($validate_data['name'])->nullable();      //create text field in case varchar(255) limit exception
                            } else {
                                $table->{$type}($validate_data['name'], $length)->nullable();   //create varchar(255) field here
                            }
                        } else {
                            $table->{$type}($validate_data['name'])->nullable();
                        }
                    });
                    $create_column = false;
                } catch (\Exception $e) {
                    $try_counter++;
                    if ($try_counter > 1) {          //dont try more than one time to create field
                        $create_column = false;
                        //throw error here
                        dd($e->getMessage());

                    } else {
                        $create_column = true;
                    }

                }
            }
        } else {
            session()->flash('flash.banner', 'Something went wrong.');
            session()->flash('flash.bannerStyle', 'success');
            $error = \Illuminate\Validation\ValidationException::withMessages([
                'server' => ['Something went wrong.'],
            ]);
            throw $error;
        }
        session()->flash('flash.banner', 'Activity field added successfully.');
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('advocacy-v2.activityFields');
    }

    public function updateActivityField(ActivityField $field, Request $request): RedirectResponse
    {
        $validate_data = $request->validate([
            'type' => ['required', 'string']
        ]);
        if (!Schema::hasColumn('advocacy_v2_user_activity', $field->name)) {
            session()->flash('flash.banner', 'Input field not found in the table.');
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->back();
        }
        $check_data_against_field = UserActivity::whereNotNull($field->name)->where($field->name, '!=', $field->default_value)->count();
        if ($check_data_against_field > 0) {
            session()->flash('flash.banner', $check_data_against_field . ' Records found against this field. you can not update this field type.');
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->back();
        }
        $type = 'string';
        if ($validate_data['type'] == 'date') {
            $type = 'date';
        } elseif ($validate_data['type'] == 'textarea' || $validate_data['type'] == 'multi_images') {
            $type = 'text';
        } elseif ($validate_data['type'] == 'integer') {
            $type = 'integer';
        }
        Schema::table('advocacy_v2_user_activity', function (Blueprint $table) use ($validate_data, $field, $type) {
            $table->{$type}($field['name'])->nullable()->change(); // You can change the column type if needed
        });
        $field->update([
            'type' => $validate_data['type']
        ]);
        session()->flash('flash.banner', 'Input field updated successfully.');
        session()->flash('flash.bannerStyle', 'success');
        return redirect()->back();
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'tehsil.id' => ['required', 'exists:tehsils,id'],
            'tehsil_activity_id' => ['required', 'exists:advocacy_v2_tehsil_activity,id'],
//                'fromToDate.start' => ['required', 'date', 'after:today'],
            'fromToDate.start' => ['required', 'date'],
//                'fromToDate.end' => ['required', 'date', 'after:today']
            'fromToDate.end' => ['required', 'date']
        ]);
        $tehsil = Tehsil::find($request->tehsil['id']);
        $tehsil_activity = TehsilActivity::findOrFail($request->tehsil_activity_id);
        $data = [
            'from_date' => date('Y-m-d', strtotime($request->get('fromToDate')['start'])),
            'to_date' => date('Y-m-d', strtotime($request->get('fromToDate')['end'])),
            'is_assigned' => true,
            'assigned_at' => now(),
            'updated_by' => auth()->id()
        ];
        $tehsil_activity->update($data);
//        Notification::send(User::whereIn('id', [36])->get(), new SmsNotification('This is a text message.', 'english'));
        Notification::send($tehsil->users()->permission('perform activity')->get(), new AssignActivityNotification($tehsil_activity, auth()->user()));
        return redirect()->back();
    }

    public function deleteActivityField(ActivityField $field, Request $request): RedirectResponse
    {
        if (Schema::hasColumn('advocacy_v2_user_activity', $field->name)) {
            $check_data_against_field = UserActivity::whereNotNull($field->name)->where($field->name, '!=', $field->default_value)->count();
            if ($check_data_against_field > 0) {
                session()->flash('flash.banner', $check_data_against_field . ' Records found against this field. you can not remove this field.');
                session()->flash('flash.bannerStyle', 'danger');
                return redirect()->back();
            }
        }
        if ($field->fieldOptions()->delete()) {
            if ($field->delete()) {
                if (Schema::hasColumn('advocacy_v2_user_activity', $field->name)) {
                    Schema::table('advocacy_v2_user_activity', function (Blueprint $table) use ($field) {
                        $table->dropColumn($field->name);
                    });
                }
            }
        }
        session()->flash('flash.banner', 'Input field removed successfully.');
        session()->flash('flash.bannerStyle', 'success');
        return redirect()->back();
    }

    public function tehsilPerformedActivities(TehsilActivity $tehsilActivity)
    {
        $user = auth()->user();
        if ($user->hasAnyRole(['DPWO', 'TPWO', 'Deputy C&T']) || $user->hasAnyRole(User::$ad_iec_user_roles)) {
            $this->authorize('viewTehsilActivities', $tehsilActivity);
//            if (@$tehsilActivity->district_activity->district->id != @auth()->user()->district()->id){
//                abort(404);
//            }
        }
        $cards = [
            'frequency' => @$tehsilActivity->district_activity->frequency,
            'district' => @$tehsilActivity->district_activity->district,
            'tehsil' => @$tehsilActivity->tehsil,
            'from_date' => date('M d,Y', strtotime($tehsilActivity->from_date)),
            'to_date' => date('M d,Y', strtotime($tehsilActivity->to_date)),
        ];
        $userActivities = $tehsilActivity->user_activities()->with('user');
        if ($user->hasAnyRole(User::$ad_iec_user_roles)) {
            $userActivities = $userActivities->where('user_id', $user->id);
        }
        $userActivities = $userActivities->get();
        foreach ($userActivities as $userActivity) {
            $userActivity->submitted_date = $userActivity->created_at->format('M d,Y');
            $userActivity->submitted_time = $userActivity->created_at->format('H:i A');
        }
        return Inertia::render('AdvocacyV2/Schedules/ViewPerformedTehsilActivities', [
            'activity' => $tehsilActivity->activity,
            'tehsil_activity' => $tehsilActivity,
            'user_activities' => $userActivities,
            'cards' => $cards
        ]);
    }

    public function showScheduleActivity(TehsilActivity $tehsilActivity, UserActivity $userActivity): Response
    {
        $user = auth()->user();
        if ($user->hasAnyRole(['DPWO', 'TPWO', 'Deputy C&T']) || $user->hasAnyrole(User::$ad_iec_user_roles)) {
            if ($user->hasAnyrole(User::$ad_iec_user_roles)) {
                if ($userActivity->user_id != $user->id) {
                    abort(403, 'THIS ACTION IS UNAUTHORIZED.');
                }
            }
            $this->authorize('viewTehsilActivities', $tehsilActivity);
//            if (@$tehsilActivity->district_activity->district->id != @auth()->user()->district()->id){
//                abort(404);
//            }
        }
        if (!$user->can('view advocacy activity') && $user->id != $userActivity->user_id) {
            abort(404);
        }
        $field_set = getAdvocacyV2ActivityForm($tehsilActivity->activity);
        foreach ($field_set as $key => $attribute) {
            $field_set[$key]['default_value'] = $userActivity->{$attribute['name']};
            if ($attribute['type'] == 'audio' || $attribute['type'] == 'video') {
                if (nfs()) {
                    if (Storage::disk('nfs')->exists($userActivity->{$attribute['name']})) {
                        $field_set[$key]['default_value'] = Storage::disk('nfs')->url($field_set[$key]['default_value']);
                    }
                } else {
                    if (Storage::disk('public')->exists($userActivity->{$attribute['name']})) {
                        $field_set[$key]['default_value'] = Storage::disk('public')->url($field_set[$key]['default_value']);
                    }
                }
            }
            if ($attribute['type'] == 'checkbox') {
                if (is_string($field_set[$key]['default_value'])) {
                    if (empty($field_set[$key]['default_value'])) {
                        $field_set[$key]['default_value'] = [];
                    } else {
                        $explodedArray = explode(",", $field_set[$key]['default_value']); // Explode the string by comma (or any delimiter)
                        if ($explodedArray !== false) {
                            $field_set[$key]['default_value'] = $explodedArray;
                        }
                    }
                }
            }
            if ($attribute['type'] == 'multi_images') {
                $images = @json_decode($userActivity->{$attribute['name']});
                $json_OK = json_last_error() == JSON_ERROR_NONE;
                $image_links = [];
                if ($json_OK) {
                    foreach ($images as $image) {
                        if (nfs()) {
                            if (Storage::disk('nfs')->exists($image)) {
                                $image_links[] = Storage::disk('nfs')->url($image);
                            }
                        } else {
                            if (Storage::disk('public')->exists($image)) {
                                $image_links[] = Storage::disk('public')->url($image);
                            }
                        }
                    }
                }
                $field_set[$key]['default_value'] = $image_links;
                $field_set[$key]['special_notice'] = $userActivity->multi_images_special_notice;
            }
        }
        $cards = [
            'frequency' => @$tehsilActivity->district_activity->frequency,
            'district' => @$tehsilActivity->district_activity->district,
            'tehsil' => @$tehsilActivity->tehsil,
            'user' => $userActivity->user,
            'submitted_at' => $userActivity ? $userActivity->created_at->format('d M,Y') : '',
            'performed_at' => @$tehsilActivity->performed_at->format('d M,Y')
        ];
        if ($tehsilActivity->performed_at && $tehsilActivity->performed_at != '') {
            $userActivity->activity_date_format = $tehsilActivity->performed_at->format('d M,Y');
        } else {
            $userActivity->activity_date_format = '--';
        }
        return Inertia::render('AdvocacyV2/Schedules/ViewActivity', [
            'activity' => $tehsilActivity->activity,
            'tehsil_activity' => $tehsilActivity,
            'user_activity' => $userActivity,
            'field_set' => $field_set,
            'cards' => $cards
        ]);
    }

    public function createScheduleActivity(TehsilActivity $tehsilActivity): Response
    {
        if (!canPerformAdvocacyActivity($tehsilActivity)) {
            abort(404);
        }
        $this->authorize('performActivity', $tehsilActivity);
        $activity = $tehsilActivity->activity;
        $districtActivity = $tehsilActivity->district_activity;
        $user = auth()->user();
        if ($user->hasAnyRole(User::$adminLevelRoles) || $user->hasAnyRole(User::$districtLevelRoles) || $user->hasAnyRole(User::$ad_iec_user_roles)) {
            if ($tehsilActivity->from_date == '') {
                $tehsilActivity->from_date = $tehsilActivity->district_activity->from_date;
            }
            if ($tehsilActivity->to_date == '') {
                $tehsilActivity->to_date = $tehsilActivity->district_activity->to_date;
            }
        }
        $data = [
            'tehsil_activity' => $tehsilActivity,
            'districtActivity' => $districtActivity,
            'activity' => $activity,
        ];
        $checkDateParameter = false;
        if ($user->hasAnyRole(User::$adminLevelRoles) || $user->hasAnyRole(User::$districtLevelSpuRoles) || $user->hasAnyRole(User::$ad_iec_user_roles)) {
            $checkDateParameter = $districtActivity;
        }
        if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
            $checkDateParameter = $tehsilActivity;
        }
        if ($tehsilActivity->is_expired == true || isScheduleExpired($checkDateParameter) || isScheduleExpired($checkDateParameter, true)) {
            $component = 'AdvocacyV2/Schedules/ScheduleExpired';
            $data['from_date'] = date('M d,Y', strtotime($checkDateParameter->from_date));
            $data['to_date'] = date('M d,Y', strtotime($checkDateParameter->to_date));
            $data['current_date'] = Carbon::now()->format('M d,Y');
            $data['backlink'] = route('advocacy-v2.schedules.index', ['by' => strtolower(auth()->user()->getRoleNames()->first()), 'performed' => false]);
        } else if (isUpcomingSchedule($checkDateParameter)) {
            $component = 'AdvocacyV2/Schedules/UpcomingSchedule';
            $data['from_date'] = date('M d,Y', strtotime($checkDateParameter->from_date));
            $data['to_date'] = date('M d,Y', strtotime($checkDateParameter->to_date));
            $dateTimeString = $checkDateParameter->from_date->format('Y-m-d H:i:s');
            $data['target_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeString)->format('Y-m-d H:i:s');
            $data['backlink'] = route('advocacy-v2.schedules.index', ['by' => strtolower(auth()->user()->getRoleNames()->first()), 'performed' => false]);
        } else {
            $form = [];
            if ($activity) {
                $form = getAdvocacyV2ActivityForm($activity);
            }
            $component = 'AdvocacyV2/Schedules/PerformActivity';
            $data['form'] = $form;
            $userActivities = $tehsilActivity->user_activities()->with('user')->where('tehsil_activity_id', $tehsilActivity->id)->get();
            foreach ($userActivities as $userActivity) {
                $userActivity->submitted_date = $userActivity->created_at->format('M d,Y');
                $userActivity->submitted_time = $userActivity->created_at->format('H:i A');
            }
            $data['user_activities'] = $userActivities;
            $data['user_activity_count'] = $userActivities->count();
            $data['expire_on'] = date('M d,Y', strtotime($tehsilActivity->to_date));
        }
        return Inertia::render($component, $data);
    }

    public function storeScheduleActivity($tehsil_activity, Request $request): RedirectResponse
    {

        $ta = TehsilActivity::findOrFail($tehsil_activity);
        if (isUpcomingSchedule($ta)) {
            session()->flash('flash.banner', 'Schedule is not started yet.');
            session()->flash('flash.bannerStyle', 'danger');
            return redirect()->back();
        }
        $this->authorize('performActivity', $ta);
        $files = [];
        $images = [];
        $fields = [];
        $rules = [];
        $messages = [];
        foreach ($request->all() as $key => $input) {
            $activityField = ActivityField::whereId($input['id'])->first()->toArray();
            $rule = [];
            if ($activityField['is_required'] == false) {
                $rule[] = 'sometimes';
                $rule[] = 'nullable';
            } else {
                $rule[] = 'required';
                $messages[$key . '.default_value.required'] = 'The field is required.';
            }
            if ($input['type'] == 'file') {
                $rule[] = 'file';
//                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt,CSV,DOC,DOCX,XLS,XLSX,PDF,TXT';
                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt';
                $rule[] = 'max:1024';
                $messages[$key . '.default_value.mimes'] = 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.';
                $messages[$key . '.default_value.max'] = 'The document file must not be greater than 2MB.';
            } elseif ($input['type'] == 'audio') {
                $rule[] = 'mimes:audio/mpeg,mpga,mp3,wav';
                $rule[] = 'max:2048';
                $messages[$key . '.default_value.mimes'] = 'The field must be a file of type: audio/mpeg,mpga,mp3,wav.';
                $messages[$key . '.default_value.max'] = 'The audio file must not be greater than 2MB.';
            } elseif ($input['type'] == 'video') {
                $rule[] = 'mimes:avi,mpeg,quicktime,mp4';
                $rule[] = 'max:50000';
                $messages[$key . '.default_value.mimes'] = 'The video file must be a file of type: avi,mpeg,quicktime,mp4.';
                $messages[$key . '.default_value.max'] = 'The video file must not be greater than 5MB.';
            } elseif ($input['type'] == 'integer' || $input['type'] == 'number') {
                $rule[] = 'integer';
                $messages[$key . '.default_value.integer'] = 'This field must be an integer.';
            } elseif ($input['type'] == 'text') {
                $rule[] = 'string';
                $messages[$key . '.default_value.string'] = 'This field must be an string.';
            } elseif ($input['type'] == 'date') {
                $rule[] = 'date';
                $messages[$key . '.default_value.date'] = 'This field must be an valid date.';
            } elseif ($input['type'] == 'multi_images') {
                if (isset($input['default_value']) && count($input['default_value']) > 0) {
                    foreach ($input['default_value'] as $miKey => $mi) {
//                        $rules[$key . '.default_value.' . $miKey][] = 'mimes:jpeg,png,bmp,JPEG,PNG,BMP';
                        $rules[$key . '.default_value.' . $miKey][] = 'mimes:jpeg,png,bmp';
                    }
                }
                $rule[] = 'array';
                $rule[] = 'max:10';
                $messages[$key . '.default_value.max'] = 'Images must not have more than 3 items.';
                $messages[$key . '.default_value.*.mimes'] = 'All images file must be a file of type: image/jpeg, image/png, image/bmp.';
            }
            $rules[$key . '.default_value'] = $rule;
        }
        if (count($rules) > 0) {
            $request->validate($rules, $messages);
        }
        foreach ($request->all() as $key => $input) {
            if ($input['type'] == 'file' || $input['type'] == 'audio' || $input['type'] == 'video' || $input['type'] == 'image') {
                if ($input['default_value'] != null) {
                    $files[] = [
                        'column' => $input['name'],
                        'file' => $input['default_value']
                    ];
                }
            } else if ($input['type'] == 'date') {
                $fields[$input['name']] = date('Y-m-d', strtotime($input['default_value']));
            } else if ($input['type'] == 'multi_images') {
//                ------------------
                if ($input['name'] == 'multi_images') {
                    foreach ($input['default_value'] as $image) {
                        $images[] = $image;
                    }
                    $fields['multi_images_special_notice'] = @$input['special_notice']['default_value'];
                } else {
                    $img_urls = [];
//                        TODO::Delete these uploaded images if activity not saved
                    foreach ($input['default_value'] as $img) {
                        $img_urls[] = upload($img);
                    }
                    if (count($img_urls) > 0) {
                        $fields[$input['name']] = json_encode($img_urls);
                    }
                }
//                ------------------
            } else if ($input['type'] == 'checkbox') {
//                ------------------
                $checkboxData = implode(',', $input['default_value']);
                $fields[$input['name']] = $checkboxData;
//                ------------------
            } else {
                $fields[$input['name']] = $input['default_value'];
            }
        }
        $fields['user_id'] = auth()->id();
        $fields['tehsil_activity_id'] = $tehsil_activity;
        $image_urls = [];
        foreach ($images as $image) {
//            $image_urls[] = $image->storePubliclyAs('files/' . auth()->id(), time() . '-' . $image->getClientOriginalName(), 'public');
            $image_urls[] = upload($image);
        }
        if (count($image_urls) > 0) {
            $fields['multi_images'] = json_encode($image_urls);
        }
        if (!isset($fields['district_id'])) {
            $fields['district_id'] = $ta->district_activity->district_id ?? 0;
        }
        $userActivity = UserActivity::create($fields);

        if ($userActivity) {
            TehsilActivity::whereId($tehsil_activity)->update([
                'performed_by' => auth()->id(),
                'is_performed' => true,
                'performed_at' => now(),
            ]);
            foreach ($files as $file) {
                $userActivity->uploadFile($file['file'], $file['column']);
            }
            $ta = TehsilActivity::whereId($tehsil_activity)->first();
            if ($ta) {
                $da = $ta->district_activity;
                $dis = $da->district;
//            Notification send to DG USER
                $districtUsers = $dis->users()->permission(['assign advocacy activity to tehsils'])->get();
                $dgUsers = User::role('DG')->permission(['create advocacy schedule'])->get();
                if ($districtUsers->count() > 0) {
                    Notification::send($districtUsers, new PerformActivityNotification($ta, $userActivity, auth()->user()));
                }
                if ($dgUsers->count() > 0) {
                    Notification::send($dgUsers, new PerformActivityNotification($ta, $userActivity, auth()->user()));
                }
            }
        }
        if (auth()->user()->can('view advocacy activity')) {
//            return redirect()->route('schedules.activity.index', $tehsil_activity);
            return redirect()->route('advocacy-v2.schedules.activity.index', ['tehsil_activity' => $tehsil_activity, 'user_activity' => $userActivity->id]);
        }
        return redirect()->back();
    }

    public function destroy($activity): RedirectResponse
    {
        $districtActivity = DistrictActivity::find($activity);

        if ($districtActivity) {
            $tehsil_activities = $districtActivity->tehsil_activities;
            foreach ($tehsil_activities as $tehsil_activity) {
                $tehsil_activity->user_activities()->delete();
            }
            $districtActivity->tehsil_activities()->delete();
            if ($districtActivity->delete()) {
                session()->flash('flash.banner', 'Advocacy schedule deleted successfully.');
                session()->flash('flash.bannerStyle', 'success');
            } else {
                session()->flash('flash.banner', 'Something went wrong.');
                session()->flash('flash.bannerStyle', 'danger');
            }
        } else {
            session()->flash('flash.banner', 'Advocacy schedule not found.');
            session()->flash('flash.bannerStyle', 'danger');
        }
        return redirect()->back();
    }

    public function destroyTehsilActivity($activity): RedirectResponse
    {
        $tehsilActivity = TehsilActivity::find($activity);
        if ($tehsilActivity) {
            $tehsilActivity->user_activities()->delete();
            if ($tehsilActivity->delete()) {
                session()->flash('flash.banner', 'Schedule deleted successfully.');
                session()->flash('flash.bannerStyle', 'success');
            } else {
                session()->flash('flash.banner', 'Something went wrong.');
                session()->flash('flash.bannerStyle', 'danger');
            }
        } else {
            session()->flash('flash.banner', 'Schedule not found.');
            session()->flash('flash.bannerStyle', 'danger');
        }
        return redirect()->back();
    }

    public function unScheduleActivities(): Response
    {
        if (auth()->user()->hasRole('super admin')) {
            abort(404);
        }
        $activities = Activity::active()->get();
        $activitiesOptions = [];
        if ($activities->count()) {
            $count = 0;
            foreach ($activities as $activity) {
                $activitiesOptions[$count] = [
                    'id' => $activity->id,
                    'text' => $activity->name,
                    'selected' => false
                ];
                ++$count;
            }
        }

        return Inertia::render('AdvocacyV2/Schedules/PerformUnScheduledActivity', [
            'activitiesOptions' => $activitiesOptions
        ]);
    }

    public function storeUnScheduleActivities(Request $request): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'activity_id' => ['required', 'exists:advocacy_v2_activities,id'],
            ]);
            $user = auth()->user();
            $files = [];
            $images = [];
            $fields = [];
            $rules = [];
            $messages = [];
            foreach ($request->all() as $key => $input) {
                if ($key === 'activity_id') {
                    continue;
                }
                $rule = [];
                $rule[] = 'required';
                if (isset($input['id'])) {
                    $activityField = ActivityField::whereId($input['id'])->first()->toArray();
                    if ($activityField['is_required'] == false) {
                        $rule[] = 'sometimes';
                        $rule[] = 'nullable';
                    } else {
                        $rule[] = 'required';
                        $messages[$key . '.default_value.required'] = 'The field is required.';
                    }
                } else {
                    $rule[] = 'required';
                    $messages[$key . '.default_value.required'] = 'The field is required.';
                }


                if ($input['type'] == 'file') {
                    $rule[] = 'file';
//                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt,CSV,DOC,DOCX,XLS,XLSX,PDF,TXT';
                    $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt';
                    $rule[] = 'max:2048';
                    $messages[$key . '.default_value.mimes'] = 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.';
                    $messages[$key . '.default_value.max'] = 'The document file size must not be greater than 2MB.';
                } elseif ($input['type'] == 'audio') {
                    $rule[] = 'mimes:audio/mpeg,mpga,mp3,wav';
                    $rule[] = 'max:1024';
                    $messages[$key . '.default_value.mimes'] = 'The field must be a file of type: audio/mpeg,mpga,mp3,wav.';
                    $messages[$key . '.default_value.max'] = 'The audio file size must not be greater than 1MB.';
                } elseif ($input['type'] == 'video') {
                    $rule[] = 'mimes:avi,mpeg,quicktime,mp4';
                    $rule[] = 'max:13000';
                    $messages[$key . '.default_value.mimes'] = 'The audio must be a file of type: avi,mpeg,quicktime,mp4.';
                    $messages[$key . '.default_value.max'] = 'The video file size must not be greater than 6MB.';
                } elseif ($input['type'] == 'integer' || $input['type'] == 'number') {
                    $rule[] = 'integer';
                    $messages[$key . '.default_value.integer'] = 'This field must be an integer.';
                } elseif ($input['type'] == 'text') {
                    $rule[] = 'string';
                    $messages[$key . '.default_value.text'] = 'This field must be an string.';
                } elseif ($input['type'] == 'date') {
                    $rule[] = 'date';
                    $messages[$key . '.default_value.date'] = 'This field must be an date.';
                } elseif ($input['type'] == 'select2') {
                    if ($input['name'] == 'frequency') {
                        $rule[] = 'exists:frequencies,id';
                        $messages[$key . '.default_value.exists'] = 'Frequencies not exist.';
                    }
                    if ($user->hasAnyRole(['super admin', 'Admin', 'DG'])) {
                        if ($input['name'] == 'district') {
                            $rule[] = 'exists:districts,id';
                            $customDistrict = $input['default_value'];
                            $messages[$key . '.default_value.exists'] = 'District not exist.';

                        }
                    }
                    if ($user->hasAnyRole(['super admin', 'Admin', 'DG', 'DPWO', 'DDPT', 'Deputy C&T'])) {
                        if ($input['name'] == 'tehsil') {
                            $rule[] = 'exists:tehsils,id';
                            $customTehsil = $input['default_value'];
                            $messages[$key . '.default_value.exists'] = 'Tehsil not exist.';
                        }
                    }

                } elseif ($input['type'] == 'multi_images') {
                    if (isset($input['default_value']) && count($input['default_value']) > 0) {
                        foreach ($input['default_value'] as $miKey => $mi) {
//                        $rules[$key . '.default_value.' . $miKey][] = 'mimes:jpeg,png,bmp,JPEG,PNG,BMP';
                            $rules[$key . '.default_value.' . $miKey][] = 'mimes:jpeg,jpg,png,bmp';
                        }
                    }
                    $rule[] = 'array';
                    $rule[] = 'max:10';
                    $messages[$key . '.default_value.max'] = 'Images must not have more than 3 items.';
                    $messages[$key . '.default_value.*.mimes'] = 'All images file must be a file of type: image/jpeg, image/png, image/bmp.';
                }
                $rules[$key . '.default_value'] = $rule;
            }
            if (count($rules) > 0) {
                $request->validate($rules, $messages);
            }
            $input = $request->input();
            $activity_id = $input['activity_id'];
            $frequency_id = (integer)$input[0]['default_value'];
            $request->request->remove(0);
            $request->request->remove('activity_id');
            if (isset($customDistrict)) {
                $district = District::find($customDistrict);
            } else {
                $district = $user->district();
            }
            if (isset($customTehsil)) {
                $tehsil = Tehsil::find($customTehsil);
            } else {
                $tehsil = $user->tehsil();
            }

            $district_activity = DistrictActivity::create([
                'district_id' => $district->id,
                'activity_id' => $activity_id,
                'frequency_id' => $frequency_id,
                'from_date' => date('Y-m-d'),
                'to_date' => date('Y-m-d'),
                'is_assigned' => true,
                'is_unscheduled' => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);
            $tehsilData = [
                'district_activity_id' => $district_activity->id,
                'tehsil_id' => $tehsil->id,
                'activity_id' => $activity_id,
                'frequency_id' => $frequency_id,
                'is_performed' => true,
                'is_unscheduled' => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ];
            $tehsil_activity = TehsilActivity::create($tehsilData);
            $performed_at = now();
            foreach ($request->all() as $key => $input) {
                if ($input['type'] == 'file' || $input['type'] == 'audio' || $input['type'] == 'video' || $input['type'] == 'image') {
                    if ($input['default_value'] != null) {
                        $files[] = [
                            'column' => $input['name'],
                            'file' => $input['default_value']
                        ];
                    }
                } else if ($input['type'] == 'date') {
                    $fields[$input['name']] = date('Y-m-d', strtotime($input['default_value']));
                    if ($input['name'] == 'activity_date') {
                        $performed_at = $input['default_value'];
                    }
                } else if ($input['type'] == 'multi_images') {
                    if ($input['name'] == 'multi_images') {
                        foreach ($input['default_value'] as $image) {
                            $images[] = $image;
                        }
                        $fields['multi_images_special_notice'] = @$input['special_notice']['default_value'];
                    } else {
                        $img_urls = [];
//                        TODO::Delete these uploaded images if activity not saved
                        foreach ($input['default_value'] as $img) {
                            $img_urls[] = upload($img);
                        }
                        if (count($img_urls) > 0) {
                            $fields[$input['name']] = json_encode($img_urls);
                        }
                    }
                } else {
                    $fields[$input['name']] = $input['default_value'];
                }
            }
            $fields['user_id'] = auth()->id();
            $fields['tehsil_activity_id'] = $tehsil_activity->id;
            $image_urls = [];
            foreach ($images as $image) {
//                $image_urls[] = $image->storePubliclyAs('files/' . auth()->id(), time() . '-' . $image->getClientOriginalName(), 'public');
                $image_urls[] = upload($image);
            }
            if (count($image_urls) > 0) {
                $fields['multi_images'] = json_encode($image_urls);
            }
            $fields['is_unscheduled'] = true;
            if (!isset($fields['district_id'])) {
                $fields['district_id'] = $district->id ?? 0;
            }
            $fields['activity_date'] = $performed_at;
            $userActivity = UserActivity::create($fields);

            if ($userActivity) {
                TehsilActivity::whereId($tehsil_activity->id)->update([
                    'performed_by' => auth()->id(),
                    'is_performed' => true,
                    'performed_at' => $performed_at,
                ]);
                foreach ($files as $file) {
                    $userActivity->uploadFile($file['file'], $file['column']);
                }
            }
            DB::Commit();
            if (auth()->user()->can('view advocacy activity')) {
                return redirect()->route('advocacy-v2.schedules.activity.index', ['tehsil_activity' => $tehsil_activity->id, 'user_activity' => $userActivity->id]);
            }
        } catch (ValidationException $e) {
            DB::rollback();
            $input = $request->input();
            foreach ($e->errors() as $key => $error) {

                if (isset($input[$key])) {
                    if (isset($input[$key]['type'])) {
                        echo ucfirst($input[$key]['title']) . ' Error:';
                        foreach ($error as $e) {
                            echo $e;
                        }
                    }
                    echo '<br>';
                } else {
                    $inputKey = explode('.', $key);
                    if (isset($inputKey[0])) {
                        if (isset($input[$inputKey[0]])) {
                            if (isset($input[$inputKey[0]]['type'])) {
                                echo ucfirst($input[$inputKey[0]]['title']) . ' Error:';
                                foreach ($error as $e) {
                                    echo '<div>' . $e . '</div>';
                                }
                            }
                            echo '<br>';
                        }
                    }
                }
            }
            exit;
        }
        return redirect()->back();
    }

    /**
     * @param Request $request
     * @param array $files
     * @param array $fields
     * @param array $images
     * @return array
     */
    public function getFieldsData(Request $request, array $files, array $fields, array $images): array
    {
        foreach ($request->all() as $key => $input) {
            if ($input['type'] == 'file' || $input['type'] == 'audio' || $input['type'] == 'video' || $input['type'] == 'image') {
                if ($input['default_value'] != null) {
                    $files[] = [
                        'column' => $input['name'],
                        'file' => $input['default_value']
                    ];
                }
            } else if ($input['type'] == 'date') {
                $fields[$input['name']] = date('Y-m-d', strtotime($input['default_value']));
            } else if ($input['type'] == 'multi_images') {
                foreach ($input['default_value'] as $image) {
                    $images[] = $image;
                }
            } else {
                $fields[$input['name']] = $input['default_value'];
            }
        }
        return array($files, $fields, $image, $images);
    }

    public function lineChart($district, Request $request)
    {
        $district = District::whereId($district)->first();
        if (!$district) {
            abort(404);
        }
        $title = 'Line Chart: ';
        if ($request->has('type') && in_array($request->type, ['scheduled', 'unassigned', 'performed', 'unPerformed', 'pending'])) {
            $title .= ucfirst($request->type) . ' For ';
        }
        $title .= ' District ' . $district->name . '.';
        $lineChartData = AdvocacyV2lineBarChart(auth()->user(), $district->id, $request->type);
        return Inertia::render('AdvocacyV2/LineChart', [
            'lineChart' => $lineChartData,
            'title' => $title
        ]);
    }

    public function mediaGallery(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $request->merge(['page_length' => $page_length]);

        return Inertia::render('AdvocacyV2/MediaGallery/Index', [
            'filter' => $request->all('district', 'tehsil', 'from_date', 'to_date', 'page_length', 'page')
        ]);
    }
}

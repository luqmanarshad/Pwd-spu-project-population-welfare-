<?php

namespace App\Http\Controllers\Advocacy;

use App\Http\Controllers\Controller;
use App\Models\Advocacy\AdvocacyActivity;
use App\Models\Advocacy\AdvocacyDistrictActivity;
use App\Models\Advocacy\AdvocacyTehsilActivity;
use App\Models\Advocacy\AdvocacyUserActivity;
use App\Models\District;
use App\Models\Tehsil;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdvocacySchedulingController extends Controller
{
    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|view advocacy schedule|assign activity to tehsils', ['only' => ['index']]);
        $this->middleware('role_or_permission:super admin|create advocacy schedule', ['only' => ['create', 'store']]);
        $this->middleware('role_or_permission:super admin|assign advocacy activity to tehsils', ['only' => ['assignSchedules', 'edit', 'update']]);
        $this->middleware('role_or_permission:super admin|delete advocacy schedule', ['only' => ['destroy']]);
        $this->middleware('role_or_permission:super admin|assign advocacy activity fields', ['only' => ['activityFields', 'assignActivityFields']]);
        $this->middleware('role_or_permission:super admin|view advocacy activity', ['only' => ['showScheduleActivity']]);
        $this->middleware('role_or_permission:super admin|perform advocacy activity', ['only' => ['createScheduleActivity', 'storeScheduleActivity']]);
        $this->middleware('permission:perform advocacy unscheduled activities', ['only' => ['unScheduleActivities', 'storeUnScheduleActivities']]);
        $this->middleware('permission:view advocacy dashboard', ['only' => ['dashboard']]);
    }

    public function dashboard(Request $request): Response
    {
        $user = auth()->user();
        $district_ids = [];
        $tehsil_ids = [];
        $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
        $cards = [];
        $total_schedules = AdvocacyDistrictActivity::query();
//        if ($user->hasRole('super admin') || $user->hasRole('DG')) {
        if ($user->hasAnyRole(User::$adminLevelRoles)) {
            $total_schedules = $total_schedules->count();
            $cards = [
                'total_activites' => [
//                    'title' => 'Scheduled In ' . $total_schedules . ' Activities',
                    'title' => 'Scheduled Activities',
                    'label' => 'Scheduled Activities',
                    'hover' => 'Total Scheduled Activities.',
                    'icon' => card_colors()['scheduled']['icon'],
                    'iconClrClass' => card_colors()['scheduled']['icon_color'],
                    'textClrClass' => card_colors()['scheduled']['text_color'],
                    'link' => route('advocacy.schedules.index'),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'assigned' => false]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'performed' => true]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'performed' => false]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'expired' => true]),
                    'count' => 0,
                    'cardBgClass' => card_colors()['expired']['card_color']
                ],
//                'total_current_month_performed_activities' => [
//                    'title' => 'This Month Performed Activities',
//                    'label' => 'This Month Performed',
//                    'hover' => 'Current Month Performed Activities',
//                    'icon' => 'clipboard-check-icon',
//                    'iconClrClass' => 'text-yellow-700',
//                    'textClrClass' => 'text-yellow-200',
//                    'link' => route('schedules.index', ['by' => 'dg', 'performed' => true, 'current_month' => true]),
//                    'count' => 0,
//                    'cardBgClass' => 'from-yellow-400 to-yellow-600'
//                ],
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
                    'link' => route('advocacy.schedules.index'),
                    'count' => 0,
                    'cardBgClass' => card_colors()['scheduled']['card_color'],
                ],
                'dpwo_total_assigned_activities' => [
                    'title' => 'Assigned Activities',
                    'label' => 'Assigned Activities',
                    'hover' => 'Total Assigned Activities',
                    'icon' => card_colors()['assigned']['icon'],
                    'iconClrClass' => card_colors()['assigned']['icon_color'],
                    'textClrClass' => card_colors()['assigned']['text_color'],
                    'link' => route('advocacy.schedules.index', ['by' => 'dpwo', 'assigned' => true]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dpwo', 'assigned' => false]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dpwo', 'performed' => true]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dpwo', 'performed' => false]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'expired' => true]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'tpwo']),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'tpwo', 'performed' => true]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'tpwo', 'performed' => false]),
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
                    'link' => route('advocacy.schedules.index', ['by' => 'dg', 'expired' => true]),
                    'count' => 0,
                    'cardBgClass' => card_colors()['expired']['card_color']
                ],
            ];
        }
        foreach ($cards as $key => $card) {
            switch ($key) {
                case 'total_activites':
                    $cards['total_activites']['count'] = AdvocacyTehsilActivity::count();
                    break;
                case 'total_unassigned_activities':
                    $cards['total_unassigned_activities']['count'] = AdvocacyTehsilActivity::unassigned()->count();
                    break;
                case 'total_performed_activities':
                    $cards['total_performed_activities']['count'] = AdvocacyUserActivity::whereHas('tehsil_activity', function ($query) {
                        $query
                            ->assigned()
                            ->performed();
                    })->count();
                    break;
                case 'total_pending_activities':
                    $cards['total_pending_activities']['count'] = AdvocacyTehsilActivity::active()

                        ->assigned()
                        ->count();
                    break;
                case 'total_expired_activities':
                    $cards['total_expired_activities']['count'] = AdvocacyTehsilActivity::inactive()

                        ->assigned()
                        ->unPerformed()
                        ->count();
                    break;
                case 'dpwo_total_scheduled_activities':
                    if (count($district_ids) > 0) {
                        $dpwo_total_scheduled_activities = AdvocacyTehsilActivity::
                            whereHas('district_activity', function ($query) use ($district_ids) {
                                return $query->whereIn('district_id', $district_ids);
                            });
                        $cards['dpwo_total_scheduled_activities']['count'] = $dpwo_total_scheduled_activities->count();
                    }
                    break;
                case 'dpwo_total_assigned_activities':
                    if (count($district_ids) > 0) {
                        $dpwo_total_assigned_activities = AdvocacyTehsilActivity::assigned()
                            ->whereHas('district_activity', function ($query) use ($district_ids) {
                                return $query->whereIn('district_id', $district_ids);
                            });
                        $cards['dpwo_total_assigned_activities']['count'] = $dpwo_total_assigned_activities->count();
                    }
                    break;
                case 'dpwo_unassigned_activities':
                    $dpwo_unassigned_activities = AdvocacyTehsilActivity::unassigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    $cards['dpwo_unassigned_activities']['count'] = $dpwo_unassigned_activities->count();
//                    $cards['dpwo_unassigned_activities']['title'] = 'Unassigned In ' . $dpwo_unassigned_activities_from . ' Activities';
                    break;
                case 'dpwo_total_performed_activities':
                    $dpwo_total_performed_activities = AdvocacyUserActivity::whereHas('tehsil_activity.district_activity', function ($query) use ($district_ids) {
                        $query->whereIn('district_id', $district_ids);
                    })->whereHas('tehsil_activity', function ($query) {
                        $query->assigned()->performed();
                    });
                    $cards['dpwo_total_performed_activities']['count'] = $dpwo_total_performed_activities->count();
                    break;
                case 'dpwo_total_pending_activities':
                    $dpwo_total_pending_activities = AdvocacyTehsilActivity::active()

                        ->assigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    $cards['dpwo_total_pending_activities']['count'] = $dpwo_total_pending_activities->count();
                    break;
                case 'dpwo_total_expired_activities':
                    $dpwo_total_expired_activities = AdvocacyTehsilActivity::inactive()

                        ->assigned()
                        ->whereHas('district_activity', function ($district_activity) use ($district_ids) {
                            $district_activity->whereIn('district_id', $district_ids);
                        });
                    $cards['dpwo_total_expired_activities']['count'] = $dpwo_total_expired_activities->count();
                    break;
                case 'tpwo_total_assigned_activities':
                    if (count($tehsil_ids) > 0) {
                        $cards['tpwo_total_assigned_activities']['count'] = AdvocacyTehsilActivity::assigned()
                            ->whereIn('tehsil_id', $tehsil_ids)
                            ->count();
                    }
                    break;
                case 'tpwo_total_performed_activities':
                    $cards['tpwo_total_performed_activities']['count'] = AdvocacyUserActivity::whereHas('tehsil_activity', function ($query) use ($tehsil_ids) {
                        $query->whereIn('tehsil_id', $tehsil_ids)

                            ->assigned()
                            ->performed();
                    })->count();
                    break;
                case 'tpwo_total_pending_activities':
                    $cards['tpwo_total_pending_activities']['count'] = AdvocacyTehsilActivity::active()

                        ->assigned()
                        ->whereIn('tehsil_id', $tehsil_ids)
                        ->count();
                    break;
                case 'tpwo_total_expired_activities':
                    $cards['tpwo_total_expired_activities']['count'] = AdvocacyTehsilActivity::inactive()

                        ->assigned()
                        ->unPerformed()
                        ->whereIn('tehsil_id', $tehsil_ids)
                        ->count();
                    break;
                default:
            }
        }
        foreach ($cards as $cardIndex => $card) {
            if ($cardIndex != 'total_activites') {
                $chart['pie']['data'][] = [
                    'name' => $card['title'],
                    'y' => $card['count'],
                    'label' => $card['label']
                ];
            }
        }
        $recentActivities = AdvocacyUserActivity::with(['tehsil_activity']);
        if ($user->hasAnyRole(User::$adminLevelRoles)) {
            $districts = District::active()->with('performed_advocacy_tehsil_activities')
                ->withCount('performed_advocacy_tehsil_activities')
                ->orderBy('performed_advocacy_tehsil_activities_count', 'DESC')
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
                $tehsilsCount = $district->performed_advocacy_tehsil_activities_count;
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
                $tehsils = $district->tehsils()->with('activities')->get();
                if ($tehsils->count() > 0) {
                    foreach ($tehsils as $ti => $tehsil) {
                        $chart['bar']['drilldown']['series'][$di]['data'][$ti] = [
                            $tehsil->name,
                            $tehsil->advocacy_activities()->active()->wherePivot('is_assigned', 1)->wherePivot('is_performed', 1)->count()
                        ];
                    }
                }
            }
            $chart['barChartData'] = getBarChart($chart['bar'], 'Top 10 District Activities Wise', 'Click District/Column to view its tehsil activities wise.', 1, $chart['bar']['drilldown']);
            $chart['lineChartData'] = lineBarChart($user);
        } else if ($user->hasAnyRole(User::$districtLevelRoles)) {
            $recentActivities = $recentActivities->whereHas('tehsil_activity.district_activity', function ($query) use ($district_ids) {
                $query->whereIn('district_id', $district_ids);
            });
            $tehsils = Tehsil::active()->whereIn('district_id', $district_ids)->with('activities')->get();
            foreach ($tehsils as $tehsil) {
                $chart['bar']['labels'][] = $tehsil->name;
                $chart['bar']['data'][] = $tehsil->advocacy_activities()->active()->wherePivot('is_assigned', 1)->wherePivot('is_performed', 1)->count();
            }
            $chart['barChartData'] = getBarChart($chart['bar'], 'Tehsil&nbsp;Activities&nbsp;Wise', '', 1);
            $chart['lineChartData'] = lineBarChart($user);
        } else if ($user->hasAnyRole(User::$tehsilLevelRoles)) {
//            $recentActivities = $recentActivities->whereIn('tehsil_id', $tehsil_ids);
            $recentActivities->whereHas('tehsil_activity', function ($query) use ($tehsil_ids) {
                $query->whereIn('tehsil_id', $tehsil_ids);
            });
            $chart = [
                'bar' => ['labels' => ''],
                'bar' => ['data' => 0],
            ];
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
                    if(nfs()){
                        if (Storage::disk('nfs')->exists($image)) {
                            $image_links[] = Storage::disk('nfs')->url($image);
                        }
                    }else{
                        if (Storage::disk('public')->exists($image)) {
                            $image_links[] = Storage::disk('public')->url($image);
                        }
                    }
                }
            }
            $shortName = Str::limit(@$recentActivity->tehsil_activity->activity->name, 60);
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
                'name_count' => strlen(@$activity->name),
                'date' => @$recentActivity->created_at->format('d M, Y'),
                'time' => @$recentActivity->created_at->format('H:s A'),
                'district' => @$districtActivity->district->name,
                'tehsil' => @$tehsilActivity->tehsil->name
            ];
        }

        list($districtsCoordinates, $mapOptions) = parse_xml_object_old_tehsils($request, 'advocacy_activities');
        return Inertia::render('Advocacy/Dashboard', [
            'total_schedules' => $total_schedules,
            'cards' => $cards,
            'chart' => $chart,
            'recentActivities' => $filteredActivities,
            'districtsCoordinates' => $districtsCoordinates,
            'mapOptions' => $mapOptions
        ]);
    }

    public function index(Request $request): Response
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $table = AdvocacyDistrictActivity::query();
        if ($request->has('grouped')){
            $grouped = (boolean)$request->grouped;
        }else{
            $grouped = false;
        }
        $showTehsil = false;
        $groupAble = ($currentUser->hasAnyRole(User::$adminLevelRoles) || $currentUser->hasAnyRole(User::$districtLevelSpuRoles));
//        if ($currentUser->hasRole('TPWO')) {
        if (!$grouped && ($request->assigned == '1' || $request->assigned == '0' || $request->performed == '1' || $currentUser->hasAnyRole(User::$adminLevelRoles) || $currentUser->hasAnyRole(User::$districtLevelSpuRoles) || $currentUser->hasAnyRole(User::$tehsilLevelSpuRoles))) {
            $table = AdvocacyTehsilActivity::query();
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
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
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
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->active()->where('id', $tehsil);
                    });
                } else {
                    $query->whereHas('tehsil', function ($tehsilQuery)use ($tehsil) {
                        $tehsilQuery->active()->where('id', $tehsil);
                    });
                }
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                $query->whereHas('activity', function ($activityQuery) use ($activity) {
                    $activityQuery->where('id', $activity);
                });
            })
            ->when($request->has('performed'), function ($query, $is_performed) use ($table, $request) {
                $is_performed = (boolean)$request->performed;
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_performed) {
                        if (!$is_performed){
                            $tehsilActivityQuery->assigned()->where('is_expired', $is_performed);
                        }else{
                            $tehsilActivityQuery->assigned()->performed();
                        }
                    });
                } else {
                    if (!$is_performed){
                        $query->assigned()->where('is_expired', $is_performed);
                    }else{
                        $query->assigned()->performed();
                    }

                }
            })
            ->when($request->has('assigned'), function ($query, $is_assigned) use ($table, $request) {
                $is_assigned = $request->assigned;
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_assigned) {
                        $tehsilActivityQuery->where('is_assigned', $is_assigned);
                    });
                } else {
                    $query->where('is_assigned', $is_assigned);
                }
            })
            ->when($request->current_month ?? null, function ($query, $current_month) use ($table) {

                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($current_month) {
                        $tehsilActivityQuery->currentMonth();
                    });
                } else {
                    $query->currentMonth();
                }
            });

        if ($request->has('expired') && in_array($request->expired, [0, 1, '0', '1', false, true])){
            if ((boolean)$request->expired){
                $activities = $activities->unperformed()->inactive();
            }
        }
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
            if ($table->getModel() instanceof AdvocacyDistrictActivity){
                $activities = $activities->whereIn('district_id', $districtIds);
            }else{
                $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($districtIds) {
                    $districtQuery->whereIn('id', $districtIds);
                });
            }
        }
        if ($request->has('from_date') && $request->has('to_date')) {
            $from_date = Carbon::make($request->input('from_date'))->format('Y-m-d');
            $to_date = Carbon::make($request->input('to_date'))->format('Y-m-d');
            $activities = $activities->where(function ($dateQuery)use ($from_date, $to_date){
                $dateQuery->whereDate('from_date', '<=', $from_date);
                $dateQuery->whereDate('to_date', '>=', $from_date);
                $dateQuery->orWhereDate('from_date', '<=', $to_date);
                $dateQuery->whereDate('to_date', '>=', $to_date);
            });
        }
//        dd($activities->toSql());
        $activities = $activities
            ->orderBy('id', 'DESC')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($advocacy_district_activity) => [
                'id' => $advocacy_district_activity->id,
                'district_activity_id' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? $advocacy_district_activity->id : $advocacy_district_activity->district_activity->id,
                'district' => isset($advocacy_district_activity->district_id) ? $advocacy_district_activity->district : ($advocacy_district_activity->district_activity ? $advocacy_district_activity->district_activity->district : ''),
                'tehsil' => isset($advocacy_district_activity->tehsil_id) ? $advocacy_district_activity->tehsil : '',
                'activity' => AdvocacyActivity::whereId($advocacy_district_activity->activity_id)->first(),
                'from_date' => validateDate($advocacy_district_activity->from_date) ? date('M d,Y', strtotime($advocacy_district_activity->from_date)) : '--',
                'to_date' => validateDate($advocacy_district_activity->to_date) ? date('M d,Y', strtotime($advocacy_district_activity->to_date)) : '--',
                'is_expired' => isScheduleExpired($advocacy_district_activity),
                'is_expired_for_assigning' => isScheduleExpired($advocacy_district_activity, true),
                'description' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? @$advocacy_district_activity->description : @$advocacy_district_activity->district_activity->description,
                'is_assigned' => $advocacy_district_activity->is_assigned,
                'delete_schedule_endpoint' => auth()->user()->can('delete schedule') ?( ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? route('advocacy.schedules.delete', $advocacy_district_activity->id) : route('advocacy.schedules.delete.tehsil', $advocacy_district_activity->id)) : false,
                'performed_activities_count' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? '' : $advocacy_district_activity->user_activities()->count(),
                'is_performed' => $this->getIsPerformedAttribute($advocacy_district_activity)
            ]);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['grouped' => (boolean)$grouped]);
        return Inertia::render('Advocacy/Schedules/Index', [
            'activities' => $activities,
            'activitiesOptions' => makeSelect2DropdownOptions(new AdvocacyActivity()),
            'showTehsil' => $showTehsil,
            'groupAble' => $groupAble,
            'filter' => $request->all('search', 'activity', 'district', 'tehsil', 'from_date', 'to_date', 'assigned', 'performed', 'expired', 'page_length', 'grouped')
        ]);
    }
    public function assignSchedules(Request $request): Response
    {
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        $table = AdvocacyDistrictActivity::query();
        $showTehsil = false;
        $activities = $table
            ->when($request->search ?? null, function ($query, $search) use ($currentUser, $table) {
                $query->whereHas('activity', function ($activity) use ($search) {
                    $activity->where('name', 'like', '%' . $search . '%');
                });
            })
            ->when($request->district ?? null, function ($query, $district) use ($table) {
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
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
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->where('id', $tehsil);
                    });
                } else {
                    $query->whereHas('district_activity.district.tehsils', function ($tehsilQuery) use ($tehsil) {
                        $tehsilQuery->where('id', $tehsil);
                    });
                }
            })
            ->when($request->has('performed'), function ($query, $is_performed) use ($table, $request) {
                $is_performed = (boolean)$request->performed;
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_performed) {
                        $tehsilActivityQuery->assigned()->where('is_performed', $is_performed);
                    });
                } else {
                    $query->assigned()->where('is_performed', $is_performed);
                }
            })
            ->when($request->has('assigned'), function ($query, $is_assigned) use ($table, $request) {
                $is_assigned = $request->assigned;
                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
                    $query->whereHas('tehsil_activities', function ($tehsilActivityQuery) use ($is_assigned) {
                        $tehsilActivityQuery->where('is_assigned', $is_assigned);
                    });
                } else {
                    $query->where('is_assigned', $is_assigned);
                }
            })
            ->when($request->current_month ?? null, function ($query, $current_month) use ($table) {

                if ($table->getModel() instanceof AdvocacyDistrictActivity) {
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
            if ($table->getModel() instanceof AdvocacyDistrictActivity){
                $activities = $activities->whereIn('district_id', $districtIds);
            }else{
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
            ->through(fn($advocacy_district_activity) => [
                'id' => $advocacy_district_activity->id,
                'district_activity_id' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? $advocacy_district_activity->id : $advocacy_district_activity->district_activity->id,
                'district' => isset($advocacy_district_activity->district_id) ? $advocacy_district_activity->district : ($advocacy_district_activity->district_activity ? $advocacy_district_activity->district_activity->district : ''),
                'tehsil' => isset($advocacy_district_activity->tehsil_id) ? $advocacy_district_activity->tehsil : '',
                'activity' => AdvocacyActivity::whereId($advocacy_district_activity->activity_id)->first(),
                'from_date' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? date('M d,Y', strtotime($advocacy_district_activity->from_date)) : date('M d,Y', strtotime($advocacy_district_activity->district_activity->from_date)),
                'to_date' => ($advocacy_district_activity instanceof AdvocacyDistrictActivity) ? date('M d,Y', strtotime($advocacy_district_activity->to_date)) : date('M d,Y', strtotime($advocacy_district_activity->district_activity->to_date)),
                'is_assigned' => $advocacy_district_activity->is_assigned,
                'is_performed' => $this->getIsPerformedAttribute($advocacy_district_activity)
            ]);
        return Inertia::render('Advocacy/Schedules/Index', [
            'activities' => $activities,
            'showTehsil' => $showTehsil,
            'filter' => [
                $request->only('search'),
                'page_length' => $page_length,
                $request->only('role')
            ],
        ]);
    }

    public function getIsPerformedAttribute($activity)
    {
        if ($activity instanceof AdvocacyTehsilActivity) {
            $is_assigned = (boolean)$activity->is_performed;
        } else if ($activity instanceof AdvocacyDistrictActivity) {
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

    public function create(): Response
    {
        return Inertia::render('Advocacy/Schedules/Create', [
//            'availableRoles' => getAllRoleOptions('DPWO'),
            'availableRoles' => getAllRoleOptions(User::$districtLevelSpuRoles),
            'availableRoleIds' => getAllRoleIds(User::$districtLevelSpuRoles),
            'districtOptions' => makeSelect2DropdownOptions(new District),
            'activityOptions' => makeSelect2DropdownOptions(new AdvocacyActivity)
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Validator::make($request->all(), [
            'districts' => ['required', 'exists:districts,id'],
            'role' => ['required', 'exists:roles,id'],
            'activity' => ['required', 'exists:advocacy_activities,id'],
            'fromToDate.start' => ['required', 'date', 'after:today'],
            'fromToDate.end' => ['required', 'date', 'after:today']
        ], [
            'fromToDate.start.required' => 'The from date field is required',
            'fromToDate.end.required' => ' & The to date field is required.',
        ])->validate();

        $districts = District::active()->whereIn('id', $request->districts)->get();
        foreach ($districts as $district) {
            foreach ($request->activities as $activity) {
                $districtData = [
                    'district_id' => $district->id,
                    'activity_id' => $activity['activity_id'],
                    'from_date' => date('Y-m-d', strtotime($request->get('fromToDate')['start'])),
                    'to_date' => date('Y-m-d', strtotime($request->get('fromToDate')['end'])),
                    'description' => $activity['description'] ?? '',
                    'is_assigned' => false,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id()
                ];
                $districtActivity = AdvocacyDistrictActivity::create($districtData);
                if ($districtActivity) {
//                    Notification::send($district->users()->permission('advocacy assign activity to tehsils')->get(), new NewScheduleNotification($districtActivity, auth()->user()));
                    foreach ($district->tehsils()->active()->get() as $tehsil) {
                        $tehsilData = [
                            'district_activity_id' => $districtActivity->id,
                            'tehsil_id' => $tehsil->id,
                            'activity_id' => $districtActivity->activity_id,
                            'is_performed' => false,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id()
                        ];
                        AdvocacyTehsilActivity::create($tehsilData);
                    }
                }
            }
        }
        return redirect()->back();
    }

//assign_activity_to_tehsils
    public function edit(Request $request, $districtActivity_id): Response
    {
        $districtActivity = AdvocacyDistrictActivity::with('district', 'activity')->findOrFail($districtActivity_id);
        $this->authorize('assignActivity', $districtActivity);
        if (isScheduleExpired($districtActivity, true)){
            $component = 'Advocacy/Schedules/ScheduleExpired';
            $data['activity'] = $districtActivity->activity;
            $data['from_date'] = date('M d,Y', strtotime($districtActivity->from_date));
            $data['to_date'] = date('M d,Y', strtotime($districtActivity->to_date));
            $data['current_date'] = date('M d,Y');
            $data['backlink'] = route('advocacy.schedules.index', [ 'by' => strtolower(auth()->user()->getRoleNames()->first()), 'assigned' => false ]);
        }else{
            $component = 'Advocacy/Schedules/Edit';
            $tehsilActivities = [];
            if ($districtActivity) {
                $tehsilActivities = $districtActivity->tehsil_activities()
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
        }

        return Inertia::render($component, $data);
    }
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'tehsil.id' => ['required', 'exists:tehsils,id'],
            'tehsil_activity_id' => ['required', 'exists:advocacy_tehsil_activity,id'],
            'fromToDate.start' => ['required', 'date'],
            'fromToDate.end' => ['required', 'date']
        ]);
        $tehsil_activity = AdvocacyTehsilActivity::findOrFail($request->tehsil_activity_id);
        $data = [
            'from_date' => date('Y-m-d', strtotime($request->get('fromToDate')['start'])),
            'to_date' => date('Y-m-d', strtotime($request->get('fromToDate')['end'])),
            'is_assigned' => true,
            'assigned_at' => now(),
            'updated_by' => auth()->id()
        ];
        $tehsil_activity->update($data);
//        Notification::send($tehsil->users()->permission('perform activity')->get(), new AssignActivityNotification($tehsil_activity, auth()->user()));
        return redirect()->back();
    }

    public function activityFields(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activities = AdvocacyActivity::active()->select('id', 'name', 'status', 'order')->with('fields')->get()->toArray();
        $filterActivities = [];
        $activityFields = makeSelect2DropdownOptions(new ActivityField);

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
        $request->merge(['page_length' => $page_length]);
        return Inertia::render('Advocacy/ActivityFields/Index', [
            'activityOptions' => $filterActivities,
            'availableFieldTypes' => getAllActivityFieldsOptions(),
            'filter' => $request->all('search', 'page_length')
        ]);
    }

    public function assignActivityFields(AdvocacyActivity $activity, Request $request): JsonResponse
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


    public function tehsilPerformedActivities(AdvocacyTehsilActivity $tehsilActivity): Response
    {
        $user = auth()->user();
        if ($user->hasAnyRole(['DPWO', 'TPWO', 'Deputy C&T'])) {
            $this->authorize('viewTehsilActivities', $tehsilActivity);
//            if (@$tehsilActivity->district_activity->district->id != @auth()->user()->district()->id){
//                abort(404);
//            }
        }
        $cards = [
            'district' => @$tehsilActivity->district_activity->district,
            'tehsil' => @$tehsilActivity->tehsil,
            'from_date' => date('M d,Y', strtotime($tehsilActivity->from_date)),
            'to_date' => date('M d,Y', strtotime($tehsilActivity->to_date)),
        ];
        $userActivities = $tehsilActivity->user_activities()->with('user');
        if ($user->hasRole('TPWO')) {
            $userActivities = $userActivities->where('user_id', $user->id);
        }
        $userActivities = $userActivities->get();
        foreach ($userActivities as $userActivity){
            $userActivity->submitted_date = $userActivity->created_at->format('M d,Y');
            $userActivity->submitted_time = $userActivity->created_at->format('H:i A');
        }
        return Inertia::render('Advocacy/Schedules/ViewPerformedTehsilActivities', [
            'activity' => $tehsilActivity->activity,
            'tehsil_activity' => $tehsilActivity,
            'user_activities' => $userActivities,
            'cards' => $cards
        ]);
    }
    public function showScheduleActivity(AdvocacyTehsilActivity $tehsilActivity, AdvocacyUserActivity $userActivity): Response
    {
        if (auth()->user()->hasAnyRole(['DPWO', 'TPWO', 'Deputy C&T'])){
            $this->authorize('viewTehsilActivities', $tehsilActivity);
//            if (@$tehsilActivity->district_activity->district->id != @auth()->user()->district()->id){
//                abort(404);
//            }
        }
        $cards = [
            'district' => @$tehsilActivity->district_activity->district,
            'tehsil' => @$tehsilActivity->tehsil,
            'user' => $userActivity->user,
            'location' => $userActivity->activity_location,
            'submitted_at' => $userActivity ? $userActivity->created_at->format('d M,Y') : '',
            'performed_at' => @$tehsilActivity->performed_at->format('d M,Y')
        ];
        $image_url = '#';
        if(nfs()){
            if (Storage::disk('nfs')->exists($userActivity->image)) {
                $image_url = Storage::disk('nfs')->url($userActivity->image);
            }
        }else{
            if (Storage::disk('public')->exists($userActivity->image)) {
                $image_url = Storage::disk('public')->url($userActivity->image);
            }
        }
        return Inertia::render('Advocacy/Schedules/ViewActivity', [
            'activity' => $tehsilActivity->activity,
            'tehsil_activity' => $tehsilActivity,
            'user_activity' => $userActivity,
            'cards' => $cards,
            'image_url' => $image_url
        ]);
    }

    public function createScheduleActivity(AdvocacyTehsilActivity $tehsilActivity): Response
    {
        $this->authorize('performActivity', $tehsilActivity);
        $activity = $tehsilActivity->activity;
        $districtActivity = $tehsilActivity->district_activity;
        $data = [
            'tehsil_activity' => $tehsilActivity,
            'districtActivity' => $districtActivity,
            'activity' => $activity,
        ];
        $checkDateParameter = false;
        $user = auth()->user();
        if ($user->hasAnyRole(User::$adminLevelRoles) || $user->hasAnyRole(User::$districtLevelSpuRoles)){
            $checkDateParameter = $districtActivity;
        }
        if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)){
            $checkDateParameter = $tehsilActivity;
        }
        if ($tehsilActivity->is_expired == true || isScheduleExpired($checkDateParameter) || isScheduleExpired($checkDateParameter, true)){
            $component = 'Advocacy/Schedules/ScheduleExpired';
            $data['from_date'] = date('M d,Y', strtotime($checkDateParameter->from_date));
            $data['to_date'] = date('M d,Y', strtotime($checkDateParameter->to_date));
            $data['current_date'] = Carbon::now()->format('M d,Y');
            $data['backlink'] = route('advocacy.schedules.index', [ 'by' => strtolower(auth()->user()->getRoleNames()->first()), 'performed' => false ]);
        }else if(isUpcomingSchedule($checkDateParameter)){
            $component = 'Advocacy/Schedules/UpcomingSchedule';
            $data['from_date'] = date('M d,Y', strtotime($checkDateParameter->from_date));
            $data['to_date'] = date('M d,Y', strtotime($checkDateParameter->to_date));
            $dateTimeString = $checkDateParameter->from_date->format('Y-m-d H:i:s');
            $data['target_date'] = Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeString)->format('Y-m-d H:i:s');
            $data['backlink'] = route('advocacy.schedules.index', [ 'by' => strtolower(auth()->user()->getRoleNames()->first()), 'performed' => false ]);
        }else{
            $form = [];
            if ($activity) {
                $form = getActivityForm($activity);
            }
            $component = 'Advocacy/Schedules/PerformActivity';
            $data['form'] = $form;
            $userActivities = $tehsilActivity->user_activities()->with('user')->where('tehsil_activity_id', $tehsilActivity->id)->get();
            foreach ($userActivities as $userActivity){
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
        $ta = AdvocacyTehsilActivity::findOrFail($tehsil_activity);
        if(isUpcomingSchedule($ta)){
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
            if ($activityField['is_required'] == false){
                $rule[] = 'sometimes';
                $rule[] = 'nullable';
            }else{
                $rule[] = 'required';
                $messages[$key.'.default_value.required'] = 'The field is required.';
            }
            if ($input['type'] == 'file') {
                $rule[] = 'file';
//                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt,CSV,DOC,DOCX,XLS,XLSX,PDF,TXT';
                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt';
                $rule[] = 'max:1024';
                $messages[$key.'.default_value.mimes'] = 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.';
                $messages[$key.'.default_value.max'] = 'The document file must not be greater than 2MB.';
            }elseif ($input['type'] == 'audio') {
                $rule[] = 'mimes:audio/mpeg,mpga,mp3,wav';
                $rule[] = 'max:2048';
                $messages[$key.'.default_value.mimes'] = 'The field must be a file of type: audio/mpeg,mpga,mp3,wav.';
                $messages[$key.'.default_value.max'] = 'The audio file must not be greater than 2MB.';
            }elseif ($input['type'] == 'video') {
                $rule[] = 'mimes:avi,mpeg,quicktime,mp4';
                $rule[] = 'max:13000';
                $messages[$key.'.default_value.mimes'] = 'The video file must be a file of type: avi,mpeg,quicktime,mp4.';
                $messages[$key.'.default_value.max'] = 'The video file must not be greater than 5MB.';
            } elseif ($input['type'] == 'integer' || $input['type'] == 'number') {
                $rule[] = 'integer';
                $messages[$key.'.default_value.integer'] = 'This field must be an integer.';
            } elseif ($input['type'] == 'text') {
                $rule[] = 'string';
                $messages[$key.'.default_value.string'] = 'This field must be an string.';
            } elseif ($input['type'] == 'date') {
                $rule[] = 'date';
                $messages[$key.'.default_value.date'] = 'This field must be an valid date.';
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
        /*$request->validate($rules, [
            '*.default_value.mimes' => 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.',
            '*.default_value.file' => 'Upload file required',
            '*.default_value.required' => 'This field is required',
            '*.default_value.integer' => 'This field must be an integer.',
            '*.default_value.date' => 'This field must be an valid date.',
            '*.default_value.*.mimes' => 'This field must be a file of type: image/jpeg, image/png, image/bmp.'
        ]);*/
        if (count($rules) > 0){
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
                foreach ($input['default_value'] as $image) {
                    $images[] = $image;
                }
                $fields['multi_images_special_notice'] = @$input['special_notice']['default_value'];

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
        $userActivity = AdvocacyUserActivity::create($fields);

        if ($userActivity) {
            AdvocacyTehsilActivity::whereId($tehsil_activity)->update([
                'performed_by' => auth()->id(),
                'is_performed' => true,
                'performed_at' => now(),
            ]);
            foreach ($files as $file) {
                $userActivity->uploadFile($file['file'], $file['column']);
            }
            $ta = AdvocacyTehsilActivity::whereId($tehsil_activity)->first();
            if ($ta){
                $da = $ta->district_activity;
                $dis = $da->district;
//            Notification send to DG USER
                $districtUsers = $dis->users()->permission(['assign activity to tehsils'])->get();
                $dgUsers = User::role('DG')->permission(['create schedule'])->get();
                if ($districtUsers->count() > 0){
                    Notification::send($districtUsers, new PerformActivityNotification($ta, $userActivity, auth()->user()));
                }
                if ($dgUsers->count() > 0){
                    Notification::send($dgUsers, new PerformActivityNotification($ta, $userActivity, auth()->user()));
                }
            }
        }
        if (auth()->user()->can('view activity')) {
//            return redirect()->route('schedules.activity.index', $tehsil_activity);
            return redirect()->route('advocacy.schedules.activity.index', ['tehsil_activity' => $tehsil_activity, 'user_activity' => $userActivity->id]);
        }
        return redirect()->route('schedules.index');
    }

    public function destroy($activity): RedirectResponse
    {
        $districtActivity = AdvocacyDistrictActivity::find($activity);

        if ($districtActivity) {
            if ($districtActivity->delete()) {
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
    public function destroyTehsilActivity($activity): RedirectResponse
    {
        $tehsilActivity = AdvocacyTehsilActivity::find($activity);
        if ($tehsilActivity) {
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
        if (auth()->user()->hasRole('super admin')){
            abort(404);
        }
        $activities = AdvocacyActivity::active()->get();
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
        return Inertia::render('Advocacy/Schedules/PerformUnScheduledActivity', [
            'activitiesOptions' => $activitiesOptions
        ]);
    }

    public function storeUnScheduleActivities(Request $request): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'activity_id' => ['required', 'exists:advocacy_activities,id'],
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
                if (isset($input['id'])){
                    $activityField = ActivityField::whereId($input['id'])->first()->toArray();
                    if ($activityField['is_required'] == false){
                        $rule[] = 'sometimes';
                        $rule[] = 'nullable';
                    }else{
                        $rule[] = 'required';
                        $messages[$key.'.default_value.required'] = 'The field is required.';
                    }
                }else{
                    $rule[] = 'required';
                    $messages[$key.'.default_value.required'] = 'The field is required.';
                }


                if ($input['type'] == 'file') {
                    $rule[] = 'file';
//                $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt,CSV,DOC,DOCX,XLS,XLSX,PDF,TXT';
                    $rule[] = 'mimes:csv,doc,docx,xls,xlsx,pdf,txt';
                    $rule[] = 'max:2048';
                    $messages[$key.'.default_value.mimes'] = 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.';
                    $messages[$key.'.default_value.max'] = 'The document file size must not be greater than 2MB.';
                }
                elseif ($input['type'] == 'audio') {
                    $rule[] = 'mimes:audio/mpeg,mpga,mp3,wav';
                    $rule[] = 'max:1024';
                    $messages[$key.'.default_value.mimes'] = 'The field must be a file of type: audio/mpeg,mpga,mp3,wav.';
                    $messages[$key.'.default_value.max'] = 'The audio file size must not be greater than 1MB.';
                }
                elseif ($input['type'] == 'video') {
                    $rule[] = 'mimes:avi,mpeg,quicktime,mp4';
                    $rule[] = 'max:13000';
                    $messages[$key.'.default_value.mimes'] = 'The audio must be a file of type: avi,mpeg,quicktime,mp4.';
                    $messages[$key.'.default_value.max'] = 'The video file size must not be greater than 6MB.';
                }
                elseif ($input['type'] == 'integer' || $input['type'] == 'number') {
                    $rule[] = 'integer';
                    $messages[$key.'.default_value.integer'] = 'This field must be an integer.';
                }
                elseif ($input['type'] == 'text') {
                    $rule[] = 'string';
                    $messages[$key.'.default_value.text'] = 'This field must be an string.';
                }
                elseif ($input['type'] == 'date') {
                    $rule[] = 'date';
                    $messages[$key.'.default_value.date'] = 'This field must be an date.';
                }
                elseif ($input['type'] == 'select2') {

                    if ($user->hasAnyRole(['super admin', 'Admin', 'DG'])){
                        if($input['name'] == 'district'){
                            $rule[] = 'exists:districts,id';
                            $customDistrict = $input['default_value'];
                            $messages[$key.'.default_value.exists'] = 'District not exist.';

                        }elseif ($input['name'] == 'tehsil'){
                            $rule[] = 'exists:tehsils,id';
                            $customTehsil = $input['default_value'];
                            $messages[$key.'.default_value.exists'] = 'Tehsil not exist.';
                        }
                    }

                }
                elseif ($input['type'] == 'multi_images') {
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
            /*$request->validate($rules, [
                '*.default_value.array' => 'Max upload limit is :max',
                '*.default_value.max' => 'Max upload limit is :max',
                '*.default_value.min' => 'Min upload limit is :min',
                '*.default_value.mimes' => 'The field must be a file of type: csv, doc, docx, xls, xlsx, pdf, txt.',
                '*.default_value.file' => 'Upload file required',
                '*.default_value.required' => 'This field is required',
                '*.default_value.integer' => 'This field must be an integer.',
                '*.default_value.date' => 'This field must be an valid date.',
                '*.default_value.*.mimes' => 'This field must be a file of type: image/jpeg, image/png, image/bmp.'
            ]);*/
            if (count($rules) > 0){
                $request->validate($rules, $messages);
            }
            $input = $request->input();
            $activity_id = $input['activity_id'];
            $request->request->remove(0);
            $request->request->remove('activity_id');
            if (isset($customDistrict)){
                $district = District::find($customDistrict);
            }else{
                $district = $user->district();
            }
            if (isset($customTehsil)){
                $tehsil = Tehsil::find($customTehsil);
            }else{
                $tehsil = $user->tehsil();
            }
            $district_activity = AdvocacyDistrictActivity::create([
                'district_id' => $district->id,
                'activity_id' => $activity_id,
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
                'is_performed' => true,
                'is_unscheduled' => true,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ];
            $tehsil_activity = AdvocacyTehsilActivity::create($tehsilData);
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
                    $fields['multi_images_special_notice'] = @$input['special_notice']['default_value'];
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
            $userActivity = AdvocacyUserActivity::create($fields);

            if ($userActivity) {
                AdvocacyTehsilActivity::whereId($tehsil_activity->id)->update([
                    'performed_by' => auth()->id(),
                    'is_performed' => true,
                    'performed_at' => now(),
                ]);
                foreach ($files as $file) {
                    $userActivity->uploadFile($file['file'], $file['column']);
                }
            }
            DB::Commit();
            if (auth()->user()->can('view activity')){
                return redirect()->route('advocacy.schedules.activity.index', ['tehsil_activity' => $tehsil_activity->id, 'user_activity' => $userActivity->id]);
            }
        } catch (ValidationException $e) {
            DB::rollback();
            $input = $request->input();
            foreach ($e->errors() as $key => $error){

                if (isset($input[$key])){
                    if (isset($input[$key]['type'])){
                        echo ucfirst($input[$key]['title']) . ' Error:';
                        foreach ($error as $e){
                            echo $e;
                        }
                    }
                    echo '<br>';
                }else{
                    $inputKey = explode('.', $key);
                    if (isset($inputKey[0])){
                        if (isset($input[$inputKey[0]])){
                            if (isset($input[$inputKey[0]]['type'])){
                                echo ucfirst($input[$inputKey[0]]['title']) . ' Error:';
                                foreach ($error as $e){
                                    echo '<div>'.$e.'</div>';
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
    public function lineChart($district, Request $request){
        $district = District::whereId($district)->first();
        if (!$district){
            abort(404);
        }
        $title = 'Line Chart: ';
        if ($request->has('type') && in_array($request->type, ['scheduled', 'unassigned', 'performed', 'unPerformed', 'pending'])){
            $title .= ucfirst($request->type) . ' For ';
        }
        $title .= ' District ' . $district->name. '.';
        $lineChartData = lineBarChart(auth()->user(), $district->id, $request->type);
        return Inertia::render('Advocacy/LineChart', [
            'lineChart' => $lineChartData,
            'title' => $title
        ]);
    }
}

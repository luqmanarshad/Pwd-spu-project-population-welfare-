<?php

namespace App\Http\Controllers\AdvocacyV2;

use App\Exports\AdvocacyV2\AllActivitiesExport;
use App\Exports\AdvocacyV2\PerformedActivitiesExport;
use App\Http\Controllers\Controller;
use App\Models\AdvocacyV2\Activity;
use App\Models\AdvocacyV2\DistrictActivity;
use App\Models\AdvocacyV2\Frequency;
use App\Models\AdvocacyV2\UserActivity;
use App\Models\AdvocacyV2\TehsilActivity;
use App\Models\District;
use App\Models\Tehsil;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportsController extends Controller
{
    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|advocacy activities line list report', ['only' => ['performedActivities', 'allActivities', 'exportPerformedActivities', 'exportAllActivities', 'exportPerformedActivitiesView', 'exportAllActivitiesView']]);
    }

//    TODO:Add Activities and frequecy Filter
    public function performedActivities(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = UserActivity::query();
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }
        $activities = $activities
            ->with('user', 'tehsil_activity', 'tehsil_activity.activity', 'tehsil_activity.tehsil', 'tehsil_activity.frequency', 'tehsil_activity.district_activity', 'tehsil_activity.district_activity.district')
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('id', $district);
                });
            })
            ->when($request->phase ?? null, function ($query, $phase) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($phaseQuery) use ($phase) {
                    $phaseQuery->where('franchising_phase_no', $phase);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil_activity.tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('id', $tehsil);
                });
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                $query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($activity) {
                    $tehsilActivityQuery->where('advocacy_v2_tehsil_activity.activity_id', $activity);
                });
            })
            ->when($request->search ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('tehsil_activity.activity', function ($activity) use ($search) {
                        $activity->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('tehsil_activity.frequency', function ($frequency) use ($search) {
                        $frequency->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('user', function ($user) use ($search) {
                        $user->where(function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%')
                                ->orWhere('contact_number', 'like', '%' . $search . '%')
                                ->orWhere('username', 'like', '%' . $search . '%');
                        });
                    });
                });
            });
        if ($request->from_date !== false && $request->from_date !== '' && $request->from_date !== null && $request->to_date !== false && $request->to_date !== '' && $request->to_date !== null) {
            if ($activity_type == 'scheduled') {
                $activities = $activities->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
            } elseif ($activity_type == 'unscheduled') {
                $activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                    $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                });
            } else {
                $activities = $activities->where(function ($query) use ($request) {
                    $query->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))])
                        ->orWhereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                            $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                        });
                });
            }
        }
        $user = auth()->user();
        if (auth()->check()) {
            if ($user->hasAnyRole(User::$districtLevelSpuRoles)) {
                $districts = auth()->user()->all_districts()->pluck('districts.id')->toArray();
                if (count($districts) > 0) {
                    $activities = $activities->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($districts) {
                        $districtQuery->whereIn('id', $districts);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
                $activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($tehsil_ids) {
                    $tehsil_activity->whereIn('tehsil_id', $tehsil_ids);
                });
            }
        }
        $activities = $activities->orderByDesc('id')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($user_activity) => [
                'id' => $user_activity->id,
                'user_activity' => $user_activity,
                'from_date' => getFormattedDate($user_activity, 'from_date'),
                'to_date' => getFormattedDate($user_activity, 'to_date'),
                'performed_at' => $user_activity->created_at ? $user_activity->created_at->format('D, M d, Y H:i A') : '',
                'is_unscheduled' => $user_activity->is_unscheduled,
                'user' => $user_activity->user,
                'activity' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity->activity : new Activity(),
                'tehsil' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity->tehsil : new Tehsil(),
                'frequency' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity->frequency : new Frequency(),
                'tehsil_activity' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity : new TehsilActivity(),
                'district_activity' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity->district_activity : new DistrictActivity(),
                'district' => $user_activity->tehsil_activity ? $user_activity->tehsil_activity->district_activity->district : new District()
            ]);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['activity_type' => $activity_type]);
        return Inertia::render('AdvocacyV2/Reports/PerformedActivities', [
            'activities' => $activities,
            'activity_type_options' => get_activity_type_dropdown_options($request->activity_type),
            'activity_options' => makeSelect2DropdownOptions(new Activity()),
            'filter' => $request->all('activity_type', 'search', 'page_length', 'frequency', 'district', 'tehsil', 'from_date', 'to_date', 'phase')
        ]);
    }

    public function performedActivitiesImages(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = UserActivity::query();
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }
        $activities = $activities
            ->with('user', 'tehsil_activity', 'tehsil_activity.activity', 'tehsil_activity.tehsil', 'tehsil_activity.frequency', 'tehsil_activity.district_activity', 'tehsil_activity.district_activity.district')
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('id', $district);
                });
            })
            ->when($request->phase ?? null, function ($query, $phase) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($phaseQuery) use ($phase) {
                    $phaseQuery->where('franchising_phase_no', $phase);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil_activity.tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('id', $tehsil);
                });
            })
            ->when($request->from_date ?? null, function ($query, $from_date) {
                $query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($from_date) {
                    $tehsilActivityQuery->whereDate('from_date', '>=', date('Y-m-d', strtotime($from_date)));
                });
            })
            ->when($request->to_date ?? null, function ($query, $to_date) {
                $query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($to_date) {
                    $tehsilActivityQuery->whereDate('to_date', '<=', date('Y-m-d', strtotime($to_date)));
                });
            })
            ->when($request->search ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('tehsil_activity.activity', function ($activity) use ($search) {
                        $activity->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('tehsil_activity.frequency', function ($frequency) use ($search) {
                        $frequency->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('user', function ($user) use ($search) {
                        $user->where(function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%')
                                ->orWhere('contact_number', 'like', '%' . $search . '%')
                                ->orWhere('username', 'like', '%' . $search . '%');
                        });
                    });
                });
            });
        $user = auth()->user();
        if (auth()->check()) {
            if ($user->hasAnyRole(User::$districtLevelSpuRoles)) {
                $districts = auth()->user()->all_districts()->pluck('districts.id')->toArray();
                if (count($districts) > 0) {
                    $activities = $activities->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($districts) {
                        $districtQuery->whereIn('id', $districts);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
                $activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($tehsil_ids) {
                    $tehsil_activity->whereIn('tehsil_id', $tehsil_ids);
                });
            }
        }
        $activities = $activities->paginate($page_length)->withQueryString();

        $field_set = [];
        foreach ($activities as $index => $activity) {
            $field_sets = $activity->tehsil_activity->activity->fields()->select('advocacy_v2_activity_fields.id', 'title', 'name', 'type', 'default_value')->get()->toArray();
            foreach ($field_sets as $key => $attribute) {
                $field_set[$index][$attribute['name']] = $activity->{$attribute['name']};
                if ($attribute['type'] == 'audio' || $attribute['type'] == 'video') {
                    if (Storage::disk('public')->exists($activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Click here to view media file" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($field_set[$index][$attribute['name']]) . '">Play<i class="pg-icon fs-12">play</i></a></div>';
                    } else {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Broken Media" class="bg-danger hover:bg-bg-danger-light rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="javascript:void(0);"><i class="fas fa-times-circle fs-12"></i></a></div>';
                    }
                }
                if ($attribute['type'] == 'file') {
                    if (Storage::disk('public')->exists($activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Click here to download file" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($field_set[$index][$attribute['name']]) . '">View<i class="pg-icon fs-12">download</i></a></div>';
                    } else {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Click here to download file" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="javascript:void(0);"><i class="fas fa-times-circle fs-12"></i></a></div>';
                    }
                }
                if ($attribute['type'] == 'multi_images') {
                    $images = @json_decode($activity->{$attribute['name']});
                    $json_OK = json_last_error() == JSON_ERROR_NONE;
                    $image_links = [];
                    $image_links = '<div class="grid grid-cols-4 gap-2 w-[300px]">';
                    if ($json_OK) {
                        foreach ($images as $image) {
                            if (Storage::disk('public')->exists($image)) {
                                $image_links .= '<div class="w-[50px] h-[50px]"><a title="Click here to view image" class="" target="_blank" href="' . Storage::disk('public')->url($image) . '"><img class="rounded-lg w-full h-[50px] object-cover" src="' . Storage::disk('public')->url($image) . '" alt="image"></a></div>';
//                                $image_links .= '<div class="w-[50px] h-[50px]"><a title="Click here to view image" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($image) . '">view<i class="pg-icon fs-12">external_link</i></a></div>';
                            }
                        }
                    }
                    $image_links .= '</div>';
                    $field_set[$index][$attribute['name']] = $image_links;
                }
            }
            if (count($field_sets) > 0 && isset($field_set[$index])) {
                $activity->field_set = $field_set[$index];
            } else {
                $activity->field_set = [];
            }
        }
        $columns = Schema::getColumnListing('advocacy_v2_user_activity');

        foreach ($columns as $key => $column) {
            if (!in_array($column, ['video_clip', 'audio_clip', 'multi_images', 'scanned_copy_of_feedback_form', 'scanned_copy_of_meeting_minutes', 'scanned_copy_of_participants_feedback', 'scanned_copy_of_permission_letter', 'scanned_copy_of_bills'])) {
                unset($columns[$key]);
            }
        }
        foreach ($columns as $key => $column) {
            $columns[$key] = [
                'column' => $column,
                'label' => @Activity::$labels[$column]
            ];
        }
        $columns = array_values($columns);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['activity_type' => $activity_type]);
        return Inertia::render('AdvocacyV2/Reports/PerformedActivitiesImages', [
            'activities' => $activities,
            'activity_type_options' => get_activity_type_dropdown_options($request->activity_type),
            'columns' => $columns,
            'filter' => $request->all('activity_type', 'search', 'page_length', 'frequency', 'district', 'tehsil', 'from_date', 'to_date', 'phase')
        ]);
    }

    public function allActivities(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = TehsilActivity::query();
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }
        $activities = $activities
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('districts.id', $district);
                });
            })
            ->when($request->phase ?? null, function ($query, $phase) {
                $query->whereHas('district_activity.district', function ($phaseQuery) use ($phase) {
                    $phaseQuery->where('franchising_phase_no', $phase);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('tehsils.id', $tehsil);
                });
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                /*$query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($activity) {
                    $tehsilActivityQuery->where('advocacy_v2_tehsil_activity.activity_id', $activity);
                });*/
                $query->where('activity_id', $activity);
            })
            ->when($request->search ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('activity', function ($activity) use ($search) {
                        $activity->where('advocacy_v2_activities.name', 'like', '%' . $search . '%');
                    })
                        ->orWhereHas('frequency', function ($frequency) use ($search) {
                            $frequency->where('advocacy_v2_frequencies.name', 'like', '%' . $search . '%');
                        });
                });
            });
        if ($request->from_date !== false && $request->from_date !== '' && $request->from_date !== null && $request->to_date !== false && $request->to_date !== '' && $request->to_date !== null) {
            if ($activity_type == 'scheduled') {
                $activities = $activities->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
            } elseif ($activity_type == 'unscheduled') {
                /*$activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                    $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                });*/
                $activities->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
            } else {
                $activities = $activities->where(function ($query) use ($request) {
                    /*$query->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))])
                        ->orWhereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                            $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                        });*/
                    $query->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))])
                        ->orWhereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                });
            }
        }
        $user = auth()->user();
        if (auth()->check()) {
            if (auth()->user()->hasAnyRole(User::$districtLevelSpuRoles)) {
                $district_ids = $user->all_districts()->pluck('districts.id')->toArray();
                if (count($district_ids) > 0) {
                    $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($district_ids) {
                        $districtQuery->whereIn('id', $district_ids);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
//                $activities = $activities->whereIn('tehsil_id', $tehsil_ids);
                $activities = $activities->whereHas('tehsil', function ($tehsilsQuery) use ($tehsil_ids) {
                    $tehsilsQuery->whereIn('tehsils.id', $tehsil_ids);
                });
            }

        }
        $activities = $activities
            ->orderByDesc('id')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($tehsil_activity) => [
                'id' => $tehsil_activity->id,
                'user_activities' => $tehsil_activity->user_activities,
                'from_date' => $tehsil_activity->from_date != '' ? $tehsil_activity->from_date->format('D, M d, Y') : '--',
                'to_date' => $tehsil_activity->to_date != '' ? $tehsil_activity->to_date->format('D, M d, Y') : '--',
                'performed_at' => getActivityDate($tehsil_activity),
                'is_unscheduled' => $tehsil_activity->is_unscheduled,
                'user' => $tehsil_activity->user_activity ? $tehsil_activity->user_activity->user : new User(),
                'activity' => $tehsil_activity->activity,
                'tehsil' => $tehsil_activity->tehsil,
                'frequency' => $tehsil_activity->frequency,
                'tehsil_activity' => $tehsil_activity,
                'district_activity' => $tehsil_activity->district_activity,
                'district' => $tehsil_activity->district_activity ? $tehsil_activity->district_activity->district : new District()
            ]);
        foreach ($activities as $activity) {
            if ($activity['id'] == 10635) {
//                dd($activity);
            }
        }
        $request->merge(['page_length' => $page_length]);
        $request->merge(['activity_type' => $activity_type]);
        return Inertia::render('AdvocacyV2/Reports/AllActivities', [
            'activities' => $activities,
            'activity_type_options' => get_activity_type_dropdown_options($request->activity_type),
            'activity_options' => makeSelect2DropdownOptions(new Activity()),
            'filter' => $request->all('activity_type', 'activity', 'search', 'page_length', 'frequency', 'district', 'tehsil', 'from_date', 'to_date', 'phase')
        ]);
    }

    public function exportPerformedActivitiesView(Request $request)
    {
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = UserActivity::with('user', 'tehsil_activity', 'tehsil_activity.activity', 'tehsil_activity.tehsil', 'tehsil_activity.frequency', 'tehsil_activity.district_activity', 'tehsil_activity.district_activity.district')
            ->when($this->filter['district'] ?? null, function ($query, $district) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('id', $district);
                });
            })
            ->when($this->filter['tehsil'] ?? null, function ($query, $tehsil) {
                $query->whereHas('advocacy_v2_tehsil_activity.tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('id', $tehsil);
                });
            })
            ->when($this->filter['from_date'] ?? null, function ($query, $from_date) {
                $query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($from_date) {
                    $tehsilActivityQuery->whereDate('from_date', '>=', date('Y-m-d', strtotime($from_date)));
                });
            })
            ->when($this->filter['to_date'] ?? null, function ($query, $to_date) {
                $query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($to_date) {
                    $tehsilActivityQuery->whereDate('to_date', '<=', date('Y-m-d', strtotime($to_date)));
                });
            })
            ->when($this->filter['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('tehsil_activity.activity', function ($activity) use ($search) {
                        $activity->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('tehsil_activity.frequency', function ($frequency) use ($search) {
                        $frequency->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('tehsil_activity.district_activity.district', function ($district) use ($search) {
                        $district->where('name', 'like', '%' . $search . '%');
                    })->orWhereHas('user', function ($user) use ($search) {
                        $user->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhere('contact_number', 'like', '%' . $search . '%')
                            ->orWhere('username', 'like', '%' . $search . '%');
                    });
                });
            });
        $user = auth()->user();
        if (auth()->check()) {
            if ($user->hasAnyRole(User::$districtLevelSpuRoles)) {
                $districts = auth()->user()->all_districts()->pluck('districts.id')->toArray();
                if (count($districts) > 0) {
                    $activities = $activities->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($districts) {
                        $districtQuery->whereIn('id', $districts);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
                $activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($tehsil_ids) {
                    $tehsil_activity->whereIn('tehsil_id', $tehsil_ids);
                });
            }
        }
        $activities = $activities->get();
        $field_set = [];
        foreach ($activities as $index => $activity) {
            $field_sets = $activity->tehsil_activity->activity->fields()->select('advocacy_v2_activity_fields.id', 'title', 'name', 'type', 'default_value')->get()->toArray();
            foreach ($field_sets as $key => $attribute) {
                $field_set[$index][$attribute['name']] = $activity->{$attribute['name']};
                if ($attribute['type'] == 'audio' || $attribute['type'] == 'video') {
                    if (Storage::disk('public')->exists($activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = Storage::disk('public')->url($field_set[$index][$attribute['name']]);
                    }
                }
                if ($attribute['type'] == 'file') {
                    if (Storage::disk('public')->exists($activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = Storage::disk('public')->url($field_set[$index][$attribute['name']]);
                    }
                }
                if ($attribute['type'] == 'multi_images') {
                    $images = @json_decode($activity->{$attribute['name']});
                    $json_OK = json_last_error() == JSON_ERROR_NONE;
                    $image_links = '';
                    $images_count = 0;
                    if ($json_OK) {
                        foreach ($images as $index => $image) {
                            if (Storage::disk('public')->exists($image)) {
                                $image_links .= Storage::disk('public')->url($image);
                                if (is_countable($images) && count($images) > ($index + 1)) {
                                    $image_links .= ' <br style="mso-data-placement:same-cell;" /> ';
                                }
                                ++$images_count;
                            }
                        }
                    }
                    $field_set[$index][$attribute['name']] = $image_links;
                    $field_set[$index][$attribute['name'] . '_count'] = $images_count;
                }
                if ($attribute['type'] == 'text' || $attribute['type'] == 'textarea' || $attribute['type'] == 'string' || $attribute['type'] == 'date' || $attribute['type'] == 'integer' || $attribute['type'] == 'number') {
                    $field_set[$index][$attribute['name']] = $activity->{$attribute['name']};
                }
            }
            if (count($field_sets) > 0) {
                $activity->field_set = $field_set[$index];
            } else {
                $activity->field_set = [];
            }
        }
        $columns = Schema::getColumnListing('advocacy_v2_user_activity');
        unset($columns[0]);
        unset($columns[1]);
        unset($columns[2]);
        unset($columns[3]);
        unset($columns[4]);
        unset($columns[7]);
        foreach ($columns as $key => $column) {
            $columns[$key] = [
                'column' => $column,
                'label' => @Activity::$labels[$column]
            ];
        }
        $columns = array_values($columns);
        $request->merge(['activity_type' => $activity_type]);
        return view('reports.advocacy_v2_activities.performed', [
            'page' => [],
            'activities' => $activities,
            'activity_type_options' => get_activity_type_dropdown_options($request->activity_type),
            'columns' => $columns,
        ]);
    }

    public function exportAllActivitiesViewBk(Request $request)
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = UserActivity::query();
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }
        $activities = $activities
            ->with(['user', 'tehsil_activity'])
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('districts.id', $district);
                });
            })
            ->when($request->phase ?? null, function ($query, $phase) {
                $query->whereHas('tehsil_activity.district_activity.district', function ($phaseQuery) use ($phase) {
                    $phaseQuery->where('franchising_phase_no', $phase);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil_activity.tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('tehsils.id', $tehsil);
                });
            })
            ->when($request->from_date ?? null, function ($query, $from_date) {
                $query->whereDate('created_at', '>=', date('Y-m-d', strtotime($from_date)));
            })
            ->when($request->to_date ?? null, function ($query, $to_date) {
                $query->whereDate('created_at', '<=', date('Y-m-d', strtotime($to_date)));
            })
            ->when($request->search ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('tehsil_activity.activity', function ($activity) use ($search) {
                        $activity->where('advocacy_v2_activities.name', 'like', '%' . $search . '%');
                    })
                        ->orWhereHas('tehsil_activity.frequency', function ($frequency) use ($search) {
                            $frequency->where('advocacy_v2_frequencies.name', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where(function ($query) use ($search) {
                                $query->where('users.name', 'like', '%' . $search . '%')
                                    ->orWhere('users.email', 'like', '%' . $search . '%')
                                    ->orWhere('users.contact_number', 'like', '%' . $search . '%')
                                    ->orWhere('users.username', 'like', '%' . $search . '%');
                            });
                        });
                });
            });
        $user = auth()->user();
        if (auth()->check()) {
            if (auth()->user()->hasAnyRole(User::$districtLevelSpuRoles)) {
                $district_ids = $user->all_districts()->pluck('districts.id')->toArray();
                if (count($district_ids) > 0) {
                    $activities = $activities->whereHas('tehsil_activity.district_activity.district', function ($districtQuery) use ($district_ids) {
                        $districtQuery->whereIn('id', $district_ids);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
//                $activities = $activities->whereIn('tehsil_id', $tehsil_ids);
                $activities = $activities->whereHas('tehsil_activity.tehsil', function ($tehsilsQuery) use ($tehsil_ids) {
                    $tehsilsQuery->whereIn('tehsils.id', $tehsil_ids);
                });
            }

        }
        $activities = $activities
            ->latest()
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($user_activity) => [
                'id' => $user_activity->id,
                'user_activity' => $user_activity,
                'from_date' => $user_activity->tehsil_activity->from_date != '' ? $user_activity->tehsil_activity->from_date->format('D, M d, Y') : '--',
                'to_date' => $user_activity->tehsil_activity->to_date != '' ? $user_activity->tehsil_activity->to_date->format('D, M d, Y') : '--',
                'performed_at' => $user_activity->created_at != '' ? $user_activity->created_at->format('D, M d, Y H:i A') : '--',
                'is_unscheduled' => $user_activity->is_unscheduled,
                'user' => $user_activity->user,
                'activity' => $user_activity->tehsil_activity->activity,
                'tehsil' => $user_activity->tehsil_activity->tehsil,
                'frequency' => $user_activity->tehsil_activity->frequency,
                'tehsil_activity' => $user_activity->tehsil_activity,
                'district_activity' => $user_activity->tehsil_activity->district_activity,
                'district' => $user_activity->tehsil_activity->district_activity->district
            ]);

        $field_set = [];
        foreach ($activities as $index => $activity) {
            $activity = (object)$activity;
            $field_sets = $activity->activity ? $activity->activity->fields()->select('activity_fields.id', 'title', 'name', 'type', 'default_value')->get()->toArray() : [];
            $user_activity = $activity->user_activity;
            foreach ($field_sets as $key => $attribute) {
                $field_set[$index][$attribute['name']] = $user_activity->{$attribute['name']};
                if ($attribute['type'] == 'audio' || $attribute['type'] == 'video') {
                    if (Storage::disk('public')->exists($user_activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Click here to view media file" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($field_set[$index][$attribute['name']]) . '">view<i class="pg-icon fs-12">play</i></a></div>';
                    }
                }
                if ($attribute['type'] == 'file') {
                    if (Storage::disk('public')->exists($user_activity->{$attribute['name']})) {
                        $field_set[$index][$attribute['name']] = '<div class="text-center"><a title="Click here to download file" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($field_set[$index][$attribute['name']]) . '">view<i class="pg-icon fs-12">download</i></a></div>';
                    }
                }
                if ($attribute['type'] == 'multi_images') {
                    $images = @json_decode($user_activity->{$attribute['name']});
                    $json_OK = json_last_error() == JSON_ERROR_NONE;
                    $image_links = [];
                    $image_links = '<div class="grid grid-cols-4 gap-2 w-[300px]">';
                    if ($json_OK) {
                        foreach ($images as $image) {
                            if (Storage::disk('public')->exists($image)) {
//                                $image_links .= '<div class="w-[50px] h-[50px]"><a title="Click here to view image" class="" target="_blank" href="' . Storage::disk('public')->url($image) . '"><img class="rounded-lg w-full h-[50px] object-cover" src="'.Storage::disk('public')->url($image).'" alt="image"></a></div>';
                                $image_links .= '<div class="w-[50px] h-[50px]"><a title="Click here to view image" class="bg-pwd-info-500 hover:bg-pwd-info-800 rounded-lg text-xs px-2 py-1 text-white" target="_blank" href="' . Storage::disk('public')->url($image) . '">view<i class="pg-icon fs-12">external_link</i></a></div>';
                            }
                        }
                    }
                    $image_links .= '</div>';
                    $field_set[$index][$attribute['name']] = $image_links;
                }
                if ($attribute['type'] == 'text' || $attribute['type'] == 'textarea' || $attribute['type'] == 'string' || $attribute['type'] == 'date' || $attribute['type'] == 'integer' || $attribute['type'] == 'number') {
                    $overflow_classes = '';
                    if (strlen($user_activity->{$attribute['name']}) > 100) {
                        $overflow_classes = 'text-ellipsis hover:text-clip hover:absolute hover:bg-white hover:p-4 hover:z-10 hover:h-fit hover:top-0 hover:bottom-0 hover:whitespace-normal w-[300px]';
                    }
                    $field_set[$index][$attribute['name']] = '<div class="' . $overflow_classes . '">' . $user_activity->{$attribute['name']} . '</div>';
                }
            }
            if (count($field_sets) > 0) {
                $activity->user_activity->field_set = $field_set[$index];
            } else {
                $activity->user_activity->field_set = [];
            }
        }
        $columns = Schema::getColumnListing('advocacy_v2_user_activity');
        unset($columns[0]);
        unset($columns[1]);
        unset($columns[2]);
        unset($columns[3]);
        unset($columns[4]);
        foreach ($columns as $key => $column) {
            $columns[$key] = [
                'column' => $column,
                'label' => @Activity::$labels[$column]
            ];
        }
        $columns = array_values($columns);
        return view('reports.advocacy_v2_activities.all', [
            'page' => [],
            'activities' => $activities,
            'columns' => $columns,
        ]);
    }

    public function exportAllActivitiesView(Request $request)
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $activity_type = $request->has('activity_type') ? $request->activity_type : 'all';
        $activities = TehsilActivity::query();
        if ($activity_type == 'scheduled') {
            $activities = $activities->scheduled();
        } elseif ($activity_type == 'unscheduled') {
            $activities = $activities->unscheduled();
        } else {
            $activity_type = 'all';
        }
        $activities = $activities
            ->when($request->district ?? null, function ($query, $district) {
                $query->whereHas('district_activity.district', function ($districtQuery) use ($district) {
                    $districtQuery->where('districts.id', $district);
                });
            })
            ->when($request->phase ?? null, function ($query, $phase) {
                $query->whereHas('district_activity.district', function ($phaseQuery) use ($phase) {
                    $phaseQuery->where('franchising_phase_no', $phase);
                });
            })
            ->when($request->tehsil ?? null, function ($query, $tehsil) {
                $query->whereHas('tehsil', function ($tehsilQuery) use ($tehsil) {
                    $tehsilQuery->where('tehsils.id', $tehsil);
                });
            })
            ->when($request->activity ?? null, function ($query, $activity) {
                /*$query->whereHas('tehsil_activity', function ($tehsilActivityQuery) use ($activity) {
                    $tehsilActivityQuery->where('advocacy_v2_tehsil_activity.activity_id', $activity);
                });*/
                $query->where('activity_id', $activity);
            })
            ->when($request->search ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('activity', function ($activity) use ($search) {
                        $activity->where('advocacy_v2_activities.name', 'like', '%' . $search . '%');
                    })
                        ->orWhereHas('frequency', function ($frequency) use ($search) {
                            $frequency->where('advocacy_v2_frequencies.name', 'like', '%' . $search . '%');
                        });
                });
            });
        if ($request->from_date !== false && $request->from_date !== '' && $request->from_date !== null && $request->to_date !== false && $request->to_date !== '' && $request->to_date !== null) {
            if ($activity_type == 'scheduled') {
                $activities = $activities->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
            } elseif ($activity_type == 'unscheduled') {
                /*$activities = $activities->whereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                    $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                });*/
                $activities->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
            } else {
                $activities = $activities->where(function ($query) use ($request) {
                    /*$query->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))])
                        ->orWhereHas('tehsil_activity', function ($tehsil_activity) use ($request) {
                            $tehsil_activity->whereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                        });*/
                    $query->whereBetween('created_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))])
                        ->orWhereBetween('performed_at', [date('Y-m-d', strtotime($request->from_date)), date('Y-m-d', strtotime($request->to_date))]);
                });
            }
        }
        $user = auth()->user();
        if (auth()->check()) {
            if (auth()->user()->hasAnyRole(User::$districtLevelSpuRoles)) {
                $district_ids = $user->all_districts()->pluck('districts.id')->toArray();
                if (count($district_ids) > 0) {
                    $activities = $activities->whereHas('district_activity.district', function ($districtQuery) use ($district_ids) {
                        $districtQuery->whereIn('id', $district_ids);
                    });
                }
            } else if ($user->hasAnyRole(User::$tehsilLevelSpuRoles)) {
                $tehsil_ids = $user->all_tehsils()->pluck('tehsils.id')->toArray();
//                $activities = $activities->whereIn('tehsil_id', $tehsil_ids);
                $activities = $activities->whereHas('tehsil', function ($tehsilsQuery) use ($tehsil_ids) {
                    $tehsilsQuery->whereIn('tehsils.id', $tehsil_ids);
                });
            }

        }
        $activities = $activities
            ->orderByDesc('id')
            ->paginate(50000)
            ->withQueryString()
            ->through(fn($tehsil_activity) => [
                'id' => $tehsil_activity->id,
                'user_activities' => $tehsil_activity->user_activities,
                'from_date' => $tehsil_activity->from_date != '' ? $tehsil_activity->from_date->format('D, M d, Y') : '--',
                'to_date' => $tehsil_activity->to_date != '' ? $tehsil_activity->to_date->format('D, M d, Y') : '--',
                'performed_at' => getActivityDate($tehsil_activity),
                'is_unscheduled' => $tehsil_activity->is_unscheduled,
                'user' => $tehsil_activity->user_activity ? $tehsil_activity->user_activity->user : new User(),
                'activity' => $tehsil_activity->activity,
                'tehsil' => $tehsil_activity->tehsil,
                'frequency' => $tehsil_activity->frequency,
                'tehsil_activity' => $tehsil_activity,
                'district_activity' => $tehsil_activity->district_activity,
                'district' => $tehsil_activity->district_activity ? $tehsil_activity->district_activity->district : new District(),
                'assigned_at' => $tehsil_activity->assigned_at ? $tehsil_activity->assigned_at->format('D, M d, Y') : '--'
            ]);
        return view('reports.advocacy_v2_activities.all', [
            'page' => [],
            'activities' => $activities,
        ]);
    }

    public function exportPerformedActivities(Request $request): BinaryFileResponse
    {
        return Excel::download(new PerformedActivitiesExport($request->all()), 'advocacy-performed-activities-report-' . time() . '.xlsx', \Maatwebsite\Excel\Excel::XLSX);
    }

    public function exportAllActivities(Request $request): BinaryFileResponse
    {
        return Excel::download(new AllActivitiesExport($request->all()), 'advocacy-all-activities-report-' . time() . '.xlsx', \Maatwebsite\Excel\Excel::XLSX);
    }
}

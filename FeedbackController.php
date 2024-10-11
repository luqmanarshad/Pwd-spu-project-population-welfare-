<?php

namespace App\Http\Controllers\Advocacy;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\PublicApp\Feedback;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|view advocacy feedbacks', ['only' => ['index']]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $feedbacks = Feedback::OrderBy('id', 'DESC')->with(['user']);

        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
            $districtIds = $districtIds->pluck('districts.id');
        } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
            $districtIds = $currentUser->all_districts()->pluck('districts.id');
        } else {
            $districtIds = false;
        }
        if ($districtIds == false) {
            $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
            if (count($tehsil_ids) > 0) {
                $feedbacks = $feedbacks->whereHas('user', function ($query) use ($tehsil_ids) {
                    return $query->whereHas('all_tehsils', function ($tehsilQuery) use ($tehsil_ids) {
                        return $tehsilQuery->whereIn('tehsils.id', $tehsil_ids);
                    });
                });
            }
        } else {
//            $feedbacks = $feedbacks->whereIn('district_id', $districtIds);
            if (count($districtIds) > 0) {
                $feedbacks = $feedbacks->whereHas('user', function ($query) use ($districtIds) {
                    return $query->whereHas('all_districts', function ($districtQuery) use ($districtIds) {
                        return $districtQuery->whereIn('districts.id', $districtIds);
                    });
                });
            }
        }

        if ($request->has('search')) {
            $feedbacks = $feedbacks->where(function ($query) use ($request) {
                $query->where('title', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('description', 'LIKE', '%' . $request->search . '%');
            });
        }
        $feedbacks = $feedbacks->paginate($page_length);

        return Inertia::render('Advocacy/FeedBack/Index',
            [
                'feedbacks' => $feedbacks,
                'filter' => [
                    $request->only('search'),
                    'page_length' => $page_length,
                ]
            ]
        );

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}

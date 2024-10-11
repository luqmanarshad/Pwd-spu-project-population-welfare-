<?php

namespace App\Http\Controllers\Advocacy;

use App\Http\Controllers\Controller;
use App\Models\Advocacy\AdvocacyActivity;
use App\Models\Advocacy\AdvocacyActivityLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdvocacyActivitiesController extends Controller
{

    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|view advocacy activities', ['only' => ['index']]);
        $this->middleware('role_or_permission:super admin|create advocacy activities', ['only' => ['create', 'store']]);
        $this->middleware('role_or_permission:super admin|edit advocacy activities', ['only' => ['update', 'updateStatus']]);
        $this->middleware('role_or_permission:super admin|delete advocacy activities', ['only' => ['delete']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $order = $request->has('order') ? $request->order : 'ASC';
        $orderBy = $request->has('orderBy') ? $request->orderBy : 'name';
        $activities = AdvocacyActivity::when($request->input('search'), function ($query, $search) {
                $query->where('name', 'LIKE', "%{$search}%");
            })
            ->orderBy($orderBy, $order)
            ->select('advocacy_activities.*')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($activity) => [
                'id' => $activity->id,
                'name' => $activity->name,
                'status' => (boolean)$activity->status,
                'order' => $activity->order,
            ]);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['order' => $order]);
        $request->merge(['orderBy' => $orderBy]);
        return Inertia::render('Advocacy/Activities/Index',[
            'activities' => $activities,
            'filter' => $request->all('search', 'page_length', 'order', 'orderBy')
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(): Response
    {
        return Inertia::render('Advocacy/Activities/Create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'name' => 'required|min:3|unique:advocacy_activities,name',
            'locations.*.name' => ['required'],
        ];
        $request->validate($rules,[
            'locations.*.name.required' => 'The location field is required'
        ]);
        $activity = AdvocacyActivity::create([
            'name' => $request->name,
            'order' => 10,
            'status' => true,
        ]);
        if ($activity){
            foreach ($request->locations as $location){
                $activity->locations()->create([
                    'name' => $location['name'],
                    'status' => true,
                ]);
            }
            return redirect()->route('advocacy.activities.edit', ['activity' => $activity->id]);
        }
        return redirect()->back();
    }


    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id): \Illuminate\Http\Response
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id): Response
    {
        $activity = AdvocacyActivity::with('locations')->whereId($id)->first();
        return Inertia::render('Advocacy/Activities/Edit', [
            'activity' => $activity,
            'locations_count' => $activity->locations->count(),
            'locations' => $activity->locations,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param  int  $id
     * @return RedirectResponse
     */
    public function update(Request $request, $id): RedirectResponse
    {
        $activity = AdvocacyActivity::findOrFail($id);
        $rules = [
            'name' => ['required', 'string', 'max:100', 'unique:advocacy_activities,name,' . $activity->id],
            'status' => ['required', 'boolean'],
            'locations.*.name' => ['required'],
        ];

        $request->validate($rules,[
            'locations.*.name.required' => 'The location field is required'
        ]);

        $update = $activity->update([
            'name' => $request->name,
            'status' => $request->status,
        ]);
        if ($update){
            foreach ($request->locations as $location){
                if (isset($location['id'])){
                    $locationExist = AdvocacyActivityLocation::where('id', $location['id'])->first();
                    if ($locationExist){
                        $locationExist->name = $location['name'];
                        $locationExist->status = $location['status'];
                        $locationExist->save();
                    }
                }else{
                    $activity->locations()->create([
                        'name' => $location['name'],
                        'status' => $location['status'],
                    ]);
                }
            }
        }
        return redirect()->route('advocacy.activities.edit', $id)->withInput();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function destroy($id): RedirectResponse
    {
        AdvocacyActivity::destroy($id);
        return redirect()->back()->withInput();
    }
    public function updateStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $activity = AdvocacyActivity::find($request->activity_id);
        if ($activity){
            $activity->status = $request->status;
            $activity->save();
        }
        return response()->json(['status' => true, 'message' => 'Activity status updated successfully.']);
    }
}

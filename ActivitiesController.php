<?php

namespace App\Http\Controllers\AdvocacyV2;

use App\Http\Controllers\Controller;
use App\Models\AdvocacyV2\Activity;
use App\Models\AdvocacyV2\Frequency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivitiesController extends Controller
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
        $activities = Activity::with('frequencies')->when($request->input('search'), function ($query, $search) {
            $query->where('advocacy_v2_activities.name', 'LIKE', "%{$search}%");
        })
            ->leftJoin('advocacy_v2_activity_frequency', 'advocacy_v2_activities.id', '=', 'advocacy_v2_activity_frequency.activity_id')
            ->leftJoin('advocacy_v2_frequencies', 'advocacy_v2_frequencies.id', '=', 'advocacy_v2_activity_frequency.frequency_id')
            ->orderBy($orderBy, $order)
            ->select('advocacy_v2_activities.*')
            ->paginate($page_length)
            ->withQueryString()
            ->through(fn($activity) => [
                'id' => $activity->id,
                'name' => $activity->name,
                'status' => (boolean)$activity->status,
                'frequencies' => $activity->frequencies,
                'order' => $activity->order,
            ]);
        $request->merge(['page_length' => $page_length]);
        $request->merge(['order' => $order]);
        $request->merge(['orderBy' => $orderBy]);
        return Inertia::render('AdvocacyV2/Activities/Index', [
            'activities' => $activities,
            'frequency_options' => makeSelect2DropdownOptions(new Frequency()),
            'filter' => $request->all('search', 'page_length', 'order', 'orderBy')
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'frequency_ids' => 'required|exists:advocacy_v2_activities,id',
            'name' => 'required|min:3|unique:advocacy_v2_activities,name',
        ]);

        $activity = Activity::create([
            'name' => $request->name,
            'order' => 10,
            'status' => true,
        ]);
        if ($activity) {
            $activity->frequencies()->attach($request->frequency_ids);
            return redirect()->route('advocacy-v2.activities.edit', ['activity' => $activity->id]);
        }
        return redirect()->back();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(): Response
    {
        return Inertia::render('AdvocacyV2/Activities/Create', [
            'frequency_options' => makeSelect2DropdownOptions(new Frequency())
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id): \Illuminate\Http\Response
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return Response
     */
    public function edit($id): Response
    {
        $activity = Activity::whereId($id)->first();
        $activityFrequencies = $activity->frequencies()->pluck('advocacy_v2_frequencies.id')->toArray();
        $activity->frequency_ids = $activityFrequencies;
        $frequency_options = makeSelect2DropdownOptions(new Frequency(), $activityFrequencies);
        return Inertia::render('AdvocacyV2/Activities/Edit', [
            'activity' => $activity,
            'frequency_options' => $frequency_options
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param int $id
     * @return RedirectResponse
     */
    public function update(Request $request, $id): RedirectResponse
    {
        $activity = Activity::findOrFail($id);

        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:advocacy_v2_activities,name,' . $activity->id],
            'frequency_ids' => 'required|exists:advocacy_v2_frequencies,id',
            'status' => ['required', 'boolean']
        ]);

        $activity->update([
            'name' => $request->name,
            'status' => $request->status,
        ]);
        $activity->frequencies()->sync($request->frequency_ids);
        return redirect()->route('advocacy-v2.activities.edit', $id)->withInput();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function destroy($id): RedirectResponse
    {
        Activity::destroy($id);
        return redirect()->back()->withInput();
    }

    public function updateStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $activity = Activity::find($request->activity_id);
        if ($activity) {
            $activity->status = $request->status;
            $activity->save();
        }
        return response()->json(['status' => true, 'message' => 'Activity status updated successfully.']);
    }
}

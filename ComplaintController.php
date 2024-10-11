<?php

namespace App\Http\Controllers\Advocacy;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\PublicApp\Complaint;
use App\Models\PublicApp\Complaintresolved;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\Province;
use Auth;
use App\Models\PublicApp\Category;

class ComplaintController extends Controller
{
    public function __construct()
    {
        $this->middleware('role_or_permission:super admin|view advocacy complaints|create callcenter integration', ['only' => ['index', 'detail', 'create']]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        $page_length = $request->has('page_length') ? $request->page_length : 10;
        $filter_request = fiFilter($request);
        $search = $request->has('search') ? $request->search : NULL;
        $complaint_counts = Complaint::selectRaw('
            COUNT(*) AS overall_count,
            SUM(CASE WHEN source_field = 1 THEN 1 ELSE 0 END) AS call_center_count,
            SUM(CASE WHEN source_field = 0 THEN 1 ELSE 0 END) AS mobile_source_count,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS Pending,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS Resolved,
            SUM(CASE WHEN status = 2 OR status = 3 THEN 1 ELSE 0 END) AS Reopened,
            SUM(CASE WHEN call_type  = "Inquiry" THEN 1 ELSE 0 END) AS Inquiries
        ');  
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
            $districtIds = $districtIds->pluck('districts.id');
        } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
            $districtIds = $currentUser->all_districts()->pluck('districts.id');
        } else {
            $districtIds = false;
        }
        $type = auth()->user()->roles->first()->name;
        
        if ($type == 'Call Center Agent') {
            $complaint_counts = $complaint_counts->where('created_by', $currentUser->id);
        } else {
            if ($districtIds == false) {
                $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
                $complaint_counts = $complaint_counts->whereIn('tehsil_id', $tehsil_ids);
            } else {
                $complaint_counts = $complaint_counts->whereIn('district_id', $districtIds);
            }
        }
        if ($filter_request['from_date'] != '' && $filter_request['to_date'] != '') {
            $complaint_counts = $complaint_counts
                ->whereDate('created_at', '>=', $filter_request['from_date'])
                ->whereDate('created_at', '<=', $filter_request['to_date']);
        }
        if ($filter_request['call_type'] != '') {
            $complaint_counts = $complaint_counts->where('call_type', $filter_request['call_type']);
        }
        $complaint_counts = $complaint_counts->first();
      
        $complaints = $this->getComplaints($filter_request, $request);
        $complaints = $complaints->paginate($page_length);    
        return Inertia::render('Advocacy/Complaint/Index',
            [
                'complaints' => $complaints,
                'complaint_counts'=>$complaint_counts,
                'filter' => [
                    $request->only('search'),
                    'page_length' => $page_length,
                ]
            ]
        );
    }

    public function exportPdf(Request $request): \Illuminate\Http\Response
    {
        $filter_request = fiFilter($request);
        $complaints = $this->getComplaints($filter_request, $request);
        view()->share([
            'complaints' => $complaints->get(),
        ]);

        $pdf = Pdf::loadView('pdf.advocacy-complaints');
        $pdf->setPaper('A4', 'landscape');
        return $pdf->stream('advocacy-complaints-' . time() . '.pdf', array("Attachment" => false));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function detail($id): Response
    {
        $singleComplaint = Complaint::where('id', $id)
            ->with(
                [
                    'category',
                    'complaintHistory',
                    'user',
                    'complaintHistory.markedByName',
                    'tehsil',
                    'district',
                    'province',
                    'division'
                ]
            )
            ->first();

        return Inertia::render('Advocacy/Complaint/Detail',
            [
                'uniqueComplaint' => $singleComplaint,
            ]
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function actionPerformed(Request $request)
    {
        $complaint = Complaint::where('id', $request->id)->first();
        if ($complaint) {

            $complaint->status = $request->actionStatus; //1 resolved && 3 for reject
            $complaint->marked_by = auth()->user()->id;
            $complaint->updated_at = Carbon::now();
            $complaint->save();
        }
        $complaintResolved = Complaintresolved::where('complaint_id', $request->id)
            ->OrderBy('id', 'DESC')
            ->first();
        if ($complaintResolved) {
            $complaintResolved->feedback = $request->remarks;
            $complaintResolved->marked_by = auth()->user()->id;
            $complaintResolved->updated_at = Carbon::now();
            $complaintResolved->save();
        }
        return redirect()->back();

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

    public function create(Request $request)
    {
        $tehsilOptions = [];
        $divisionOptions = [];
        $districts = District::active()->get();
        $provinces = Province::active()->get();
        $category = Category::OrderBy('id', 'asc')->get();
        $districtOptions = [];
        $provinceOptions = getProvincesOptionsForSelect2($provinces);
        $categorieOptions = getCategoryOptionsForSelect2($category);
        return Inertia::render('Advocacy/Complaint/Create', [
            'provinceOptions' => $provinceOptions,
            'divisionOptions' => $divisionOptions,
            'districtOptions' => $districtOptions,
            'tehsil_options' => [],
            'categorieOptions' => $categorieOptions
        ]);
    }

    public function store(Request $request)
    {

        // Define your custom validation rules and messages
        $rules = Complaint::RULES;
        $messages = Complaint::MESSAGES;

        // // Validate the request data
        $validator = Validator::make($request->all(), $rules, $messages)->validate();

    //    dd($request->All());
       $callCenterIntegration = new Complaint();
       $callCenterIntegration->call_type = $request->call_type;
       $callCenterIntegration->category_id = $request->category_type;
       $callCenterIntegration->customer_name = $request->customer_name;
       $callCenterIntegration->customer_contact_number = $request->contact_number['number'] ?? '';
       $callCenterIntegration->customer_contact_number_formatted = $request->contact_number['formatted'] ?? '';
       $callCenterIntegration->province_id  = $request->province;
       $callCenterIntegration->division_id = $request->division;
       $callCenterIntegration->district_id = $request->district;
       $callCenterIntegration->tehsil_id = $request->tehsil;
       $callCenterIntegration->ucs = $request->uc_name;
       $callCenterIntegration->detail_of_inquiry = $request->detail_of_inquairy_Complaint;
       $callCenterIntegration->street_no_name = $request->street_No_name;
       $callCenterIntegration->house_shop_no = $request->house_shop_no;
       $callCenterIntegration->provided_answer = $request->provided_answer;
       $callCenterIntegration->agents_comments = $request->agents_comments;
       $callCenterIntegration->complaint_status = $request->complaint_status;
    //    $callCenterIntegration->secondry_contact_number = $request->secondry_contact_number;
       $callCenterIntegration->secondry_contact_number_formatted = $request->secondry_contact_number['formatted'];
       $callCenterIntegration->faqs_send_by_sms = $request->faqs_send_by_sms;
       $callCenterIntegration->source_field = 1;  //for call center
       $callCenterIntegration->status = 0;
       $callCenterIntegration->created_at = now();
       $callCenterIntegration->created_by = Auth::id();

       $callCenterIntegration->save();
       return redirect()->back();
    }

    /**
     * @param array $filter_request
     * @param Request $request
     * @return mixed
     */
    private function getComplaints(array $filter_request, Request $request)
    {
        $complaints = Complaint::OrderBy('id', 'desc')->with(['category', 'user', 'province', 'division']);
        $currentUser = auth()->user();
        $districtIds = District::query()->active();
        if ($currentUser->hasAnyRole(User::$adminLevelRoles)) {
            $districtIds = $districtIds->pluck('districts.id');
        } elseif ($currentUser->hasAnyRole(User::$districtLevelRoles)) {
            $districtIds = $currentUser->all_districts()->pluck('districts.id');
        } else {
            $districtIds = false;
        }
        $type = auth()->user()->roles->first()->name;
        if ($type == 'Call Center Agent') {
            $complaints = $complaints->where('created_by', $currentUser->id);
        } else {
            if ($districtIds == false) {
                $tehsil_ids = $currentUser->all_tehsils()->pluck('tehsils.id');
                $complaints = $complaints->whereIn('tehsil_id', $tehsil_ids);
            } else {
                $complaints = $complaints->whereIn('district_id', $districtIds);
            }
        }


        if ($filter_request['from_date'] != '' && $filter_request['to_date'] != '') {
            $complaints = $complaints
                ->whereDate('created_at', '>=', $filter_request['from_date'])
                ->whereDate('created_at', '<=', $filter_request['to_date']);
        }
        if ($filter_request['call_type'] != '') {
            $complaints = $complaints->where('call_type', $filter_request['call_type']);
        }
        if ($request->has('search')) {
            if ($request->search == 'TPWO') {
                $markedTo = 0;
            } elseif ($request->search == 'DPWO') {
                $markedTo = 1;
            } elseif ($request->search == 'DG') {
                $markedTo = 2;
            } else {
                $markedTo = null;
            }

            if ($markedTo == null) {
                $complaints = $complaints->where(function ($query) use ($request, $markedTo) {

                    $query->where('title', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('call_type', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('description', 'LIKE', '%' . $request->search . '%')
                        ->orWhereHas('category', function ($categoryQuery) use ($request) {
                            $categoryQuery->where('name', 'LIKE', '%' . $request->search . '%');
                        })
                        ->orWhereHas('tehsil', function ($tehsilQuery) use ($request) {
                            $tehsilQuery->where('name', 'LIKE', '%' . $request->search . '%');
                        })
                        ->orWhereHas('district', function ($districtQuery) use ($request) {
                            $districtQuery->where('name', 'LIKE', '%' . $request->search . '%');
                        })
                        ->orWhereHas('user', function ($userQuery) use ($request) {
                            $userQuery->where('name', 'LIKE', '%' . $request->search . '%');
                        });
                });
            } else {
                $complaints->where('is_forwarded', $markedTo);
            }
        }
        return $complaints;
    }
}

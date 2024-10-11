<?php

namespace App\Http\Controllers\Advocacy;

use App\Http\Controllers\Controller;
use App\Models\Advocacy\AdvocacyServiceMethod;
use App\Models\Center;
use App\Models\PublicApp\FAQ;
use App\Models\Spu\MediaGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class GeneralPublicController extends Controller
{
    public function services(Request $request): \Inertia\Response
    {
        if (!$request->has('latitude') && !$request->has('longitude')){
            return Inertia::render('Advocacy/GeneralPublic/ValidationFailed', [
                'error_title' => 'Blocked!!!',
                'error_message' => 'Latitude and Longitude fields are required.'
            ]);
        }
        if (!$request->has('center_type')){
            return Inertia::render('Advocacy/GeneralPublic/ValidationFailed', [
                'error_title' => 'Blocked!!!',
                'error_message' => 'Center type field is required.'
            ]);
        }
        if (!in_array($request->center_type, ['FWC', 'FHC', 'BHU', 'PVT_SDU'])){
            return Inertia::render('Advocacy/GeneralPublic/ValidationFailed', [
                'error_title' => 'Blocked!!!',
                'error_message' => 'Allowed Center types are FWC, FHC, BHU, PVT_SDU.'
            ]);
        }
        // dd($request->all());
        $centers = Center::closeTo($request->latitude, $request->longitude)
            // ->whereNotNull('c_location')
            ->where('c_center_type', $request->center_type)
            ->orderBy('distance', 'ASC')
            ->get();
        // dd($centers);
        foreach ($centers as $i => $center){
            if ($center->c_latitude != '' && $center->c_longitude != ''){
                $mapUrl = 'https://www.google.com/maps/dir/?api=1&origin='.$request->latitude.','.$request->longitude.'&destination='.$center->c_latitude.','.$center->c_longitude;
            }else{
                $mapUrl = '';
            }
            $center->map_url = $mapUrl;
            $center->distance = round($center->distance,2);
        }
        return Inertia::render('Advocacy/GeneralPublic/Services', [
            'centers' => $centers,
            'title' => 'Nearest ' . $request->center_type
        ]);
    }

    public function medias(Request $request): \Inertia\Response
    {
        $is_counselling = $request->has('counselling');
        $request->merge(['is_counselling' => $is_counselling]);
        $title = 'Advocacy Medias';
        if ($is_counselling) {
            $title = 'Counselling And Advocacy Medias';
        }
        return Inertia::render('Advocacy/GeneralPublic/Medias', [
            'title' => $title,
            'filter' => $request->all('page', 'is_counselling')
        ]);
    }

    public function getMedias(Request $request): \Illuminate\Http\JsonResponse
    {
        $medias = MediaGallery::active()->approved()->accepted();
        if ($request->has('is_counselling') && $request->is_counselling) {
            $medias = $medias->iecMaterial();
        } else {
            $medias = $medias->advocacy();
        }
        $medias = $medias
            ->latest()
            ->paginate(8)
            ->withPath(route('advocacy.general_public.medias'))
            ->withQueryString()
            ->through(fn($media) => [
                'id' => $media->id,
                'name' => $media->name,
                'mime_type' => $media->mime_type,
                'district' => $media->district,
                'tehsil' => $media->tehsil,
                'by' => $media->created_by,
                'upload_type' => $media->upload_type,
                'url' => getMediaGalleryItem($media),
                'title' => $media->title
            ]);
        return response()->json($medias, 200);
    }


    public function servicesMethods(Request $request, $service_method): \Inertia\Response
    {
        $service_method = AdvocacyServiceMethod::with('contraceptives', 'contraceptives.advantages', 'contraceptives.types', 'contraceptives.types.properties')
            ->where('id', $service_method)
            ->first();
        if (!$service_method){
            return Inertia::render('Advocacy/GeneralPublic/ValidationFailed', [
                'error_title' => 'Not Found Error!!!',
                'error_message' => 'Service method not found.'
            ]);
        }
        $method_type = ($service_method->method_type == ADVOCACY_SERVICE_METHOD_TYPE_MALE ? 'Male' : 'Female');
//        dd($service_method);
        $lang = '';
        if ($request->has('lang') && in_array($request->lang, ['en', 'ur'])) {
            if ($request->lang == 'ur') {
                $lang = '_' . $request->lang;
            }
        }
        return Inertia::render('Advocacy/GeneralPublic/ServiceMethodContraceptives', [
            'service_method' => $service_method,
            'title' => $service_method->{'name' . $lang},
            'lang' => $lang
        ]);
    }

    public function faqs(Request $request)
    {
        // $faqs = [
        //     [
        //         'question' => 'What is PWD-Advocacy',
        //         'answer' => 'The advocacy complain can only be taken forward and followed-up by using modern techniques of advocacy and effective communication'
        //     ],
        //     [
        //         'question' => 'Number of Centers in PWD',
        //         'answer' => 'The advocacy complain can only be taken forward and followed-up by using modern techniques of advocacy and effective communication'
        //     ],
        //     [
        //         'question' => 'Nearest FWC /FHC center',
        //         'answer' => 'The advocacy complain can only be taken forward and followed-up by using modern techniques of advocacy and effective communication'
        //     ]
        // ];
        $lang = '';
        if ($request->has('lang') && in_array($request->lang, ['en', 'ur'])) {
            if ($request->lang == 'ur') {
                $lang = '_' . $request->lang;
            }
        }
        $faqs = FAQ::where('status', 1)
            ->when($request->search ?? null, function ($query, $search) use ($lang) {
                $query->where('question' . $lang, 'LIKE', "%$search%");
            })
            ->get();
        return Inertia::render('Advocacy/GeneralPublic/Faqs', [
            'faqs' => $faqs,
            'lang' => $lang,
            'filter' => $request->all('search')
        ]);
    }
    /**
     * @throws \Exception
     */
    public function uploadContentSM(Request $request): \Illuminate\Http\JsonResponse
    {
        $file = $request->file('uploaded_file');
        if ($file) {
            $filename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension(); //Get extension of uploaded file
            $tempPath = $file->getRealPath();
            $fileSize = $file->getSize(); //Get size of uploaded file in bytes
//Check for file extension and size
            $this->checkUploadedFileProperties($extension, $fileSize);
//Where uploaded file will be stored on the server
            $location = 'uploads'; //Created an "uploads" folder for that
// Upload file
            $file->move($location, $filename);
// In case the uploaded file path is to be stored in the database
            $filepath = public_path($location . "/" . $filename);
// Reading file
            $file = fopen($filepath, "r");
            $importData_arr = array(); // Read through the file and store the contents as an array
            $i = 0;
//Read the contents of the uploaded file
            while (($filedata = fgetcsv($file, 1000, ",")) !== FALSE) {
                $num = count($filedata);
// Skip first row (Remove below comment if you want to skip the first row)
                if ($i == 0) {
                    $i++;
                    continue;
                }
                for ($c = 0; $c < $num; $c++) {
                    $importData_arr[$i][] = $filedata[$c];
                }
                $i++;
            }
            fclose($file); //Close after reading
            $j = 0;
            $c_code = 90517;
            foreach ($importData_arr as $importData) {
                $mobilizer_id = $importData[0];
                $mobilizer_name = $importData[1];
                $mobilizer_address = $importData[2];
                $mobilizer_district_idFk = $importData[3];
                $mobilizer_tehsil_idFk = $importData[4];
                $mobilizer_union_number = $importData[5];
                $mobilizer_union = $importData[6];
                $c_center_type_idFk = 6;

                $j++;
                try {
                    DB::beginTransaction();

                    $center = Center::where('c_incharge_name', $mobilizer_name)
                        ->where('district_idFk', $mobilizer_district_idFk)
                        ->where('tehsil_idFk', $mobilizer_tehsil_idFk)
                        ->first();
                    if ($center){
                    }else{
                        if ($c_center_type_idFk == 1){
                            $center_type = 'FHC';
                        }elseif ($c_center_type_idFk == 2){
                            $center_type = 'RHS-B';
                        }elseif ($c_center_type_idFk == 3){
                            $center_type = 'FWC';
                        }elseif ($c_center_type_idFk == 4){
                            $center_type = 'FHMU';
                        }elseif ($c_center_type_idFk == 5){
                            $center_type = 'MSU';
                        }elseif ($c_center_type_idFk == 6){
                            $center_type = 'MOBILIZER';
                        }else{
                            $center_type = 'VASECTOMY';
                        }
                        Center::create([
                            'c_incharge_name' => $mobilizer_name,
                            'c_code' => ++$c_code,
                            'c_name' => $mobilizer_name,
                            'c_address' => $mobilizer_address,
                            'c_scheme_type' => 'CENTER',
                            'c_center_type' => $center_type,
                            'c_union_number' => $mobilizer_union_number,
                            'c_union' => $mobilizer_union,
                            'province_idFk' => 1,
                            'district_idFk' => $mobilizer_district_idFk,
                            'tehsil_idFk' => $mobilizer_tehsil_idFk,
                            'c_location' => '',
                            'u_idFk' => 0,
                            'c_status' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::commit();
                } catch (\Exception $e) {
                    dd($e->getMessage());
                    DB::rollBack();
                }
            }
            return response()->json([
                'message' => "$j records successfully uploaded"
            ]);
        } else {
//no file was uploaded
            throw new \Exception('No file was uploaded', ResponseAlias::HTTP_BAD_REQUEST);
        }
    }
    public function uploadContentCenters(Request $request): \Illuminate\Http\JsonResponse
    {
        $file = $request->file('uploaded_file');
        if ($file) {
            $filename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension(); //Get extension of uploaded file
            $tempPath = $file->getRealPath();
            $fileSize = $file->getSize(); //Get size of uploaded file in bytes
//Check for file extension and size
            $this->checkUploadedFileProperties($extension, $fileSize);
//Where uploaded file will be stored on the server
            $location = 'uploads'; //Created an "uploads" folder for that
// Upload file
            $file->move($location, $filename);
// In case the uploaded file path is to be stored in the database
            $filepath = public_path($location . "/" . $filename);
// Reading file
            $file = fopen($filepath, "r");
            $importData_arr = array(); // Read through the file and store the contents as an array
            $i = 0;
//Read the contents of the uploaded file
            while (($filedata = fgetcsv($file, 1000, ",")) !== FALSE) {
                $num = count($filedata);
// Skip first row (Remove below comment if you want to skip the first row)
                if ($i == 0) {
                    $i++;
                    continue;
                }
                for ($c = 0; $c < $num; $c++) {
                    $importData_arr[$i][] = $filedata[$c];
                }
                $i++;
            }
            fclose($file); //Close after reading
            $j = 0;
            $c_code = 90517;
            foreach ($importData_arr as $importData) {
//                dd($importData);
                /*centersModel*/
                $c_id = $importData[0];
                $c_incharge_name = $importData[1];
                $c_center_name = $importData[2];
                $c_address = $importData[3];
                $c_center_type_idFk = $importData[4];
                $perf_location = $importData[5];
                $c_district_idFK = $importData[6];
                $c_tehsil_idFK = $importData[7];
                $d_name = $importData[8];
                $th_name = $importData[9];
                $center_table = $importData[10];

                $j++;
                try {
                    DB::beginTransaction();

                    $center = Center::where('c_incharge_name', $c_incharge_name)
                        ->where('district_idFk', $c_district_idFK)
                        ->where('tehsil_idFk', $c_tehsil_idFK)
                        ->first();
                    $c_latitude = '';
                    $c_longitude = '';
                    if ($perf_location != ''){
                        $locationArray = explode(',', $perf_location);
                        $c_latitude = @$locationArray[0];
                        $c_longitude = @$locationArray[1];
                    }
                    if ($center){
                        $center->c_location = $perf_location;
                        $center->c_latitude = $c_latitude;
                        $center->c_longitude = $c_longitude;
                        $center->save();
                    }else{
                        if ($c_center_type_idFk == 1){
                            $center_type = 'FHC';
                        }elseif ($c_center_type_idFk == 2){
                            $center_type = 'RHS-B';
                        }elseif ($c_center_type_idFk == 3){
                            $center_type = 'FWC';
                        }elseif ($c_center_type_idFk == 4){
                            $center_type = 'FHMU';
                        }elseif ($c_center_type_idFk == 5){
                            $center_type = 'MSU';
                        }elseif ($c_center_type_idFk == 6){
                            $center_type = 'MOBILIZER';
                        }else{
                            $center_type = 'VASECTOMY';
                        }

                        Center::create([
                            'c_incharge_name' => $c_incharge_name,
                            'c_code' => ++$c_code,
                            'c_name' => $c_center_name,
                            'c_address' => $c_address,
                            'c_scheme_type' => 'CENTER',
                            'c_center_type' => $center_type,
                            'c_union_number' => '',
                            'c_union' => '',
                            'province_idFk' => 1,
                            'district_idFk' => $c_district_idFK,
                            'tehsil_idFk' => $c_tehsil_idFK,
                            'c_location' => $perf_location,
                            'c_latitude' => $c_latitude,
                            'c_longitude' => $c_longitude,
                            'u_idFk' => 0,
                            'c_status' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::commit();
                } catch (\Exception $e) {
                    dd($e->getMessage());
                    DB::rollBack();
                }
            }
            return response()->json([
                'message' => "$j records successfully uploaded"
            ]);
        } else {
//no file was uploaded
            throw new \Exception('No file was uploaded', ResponseAlias::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @throws \Exception
     */
    public function checkUploadedFileProperties($extension, $fileSize)
    {
        $valid_extension = array("csv", "xlsx"); //Only want csv and excel files
        $maxFileSize = 2097152; // Uploaded file size limit is 2mb
        if (in_array(strtolower($extension), $valid_extension)) {
            if ($fileSize <= $maxFileSize) {
            } else {
                throw new \Exception('No file was uploaded', ResponseAlias::HTTP_REQUEST_ENTITY_TOO_LARGE); //413 error
            }
        } else {
            throw new \Exception('Invalid file extension', ResponseAlias::HTTP_UNSUPPORTED_MEDIA_TYPE); //415 error
        }
    }
}

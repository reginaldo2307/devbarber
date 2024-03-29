<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\User;
use App\Models\UserAppointment;
use App\Models\UserFavorite;
use App\Models\Barber;
use App\Models\BarberPhotos;
use App\Models\BarberServices;
use App\Models\BarberTestimonial;
use App\Models\BarberAvailability;


class BarberController extends Controller
{
    private $loggedUser;

    public function __construct() {
        $this->middleware('auth:api');
        $this->loggedUser = auth()->user();
    }

    
    
    private function searchGeo($address) {
        $key = env('MAPS_GEO', null);

        $address = urlencode($address);

        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='.$address.'key='.$key;
        $ch = curl_init();
        curl_seopt($ch, CURLOPTURL, $url);
        curl_seopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $res = curl_exec($ch);
        curl_close($ch);

        return json_decode($res, true);
    }




    public function list(Request $request) {
        $array = ['error' => ''];

        $lat = $request->input('lat');
        $lng = $request->input('lng');
        $city = $request->input('city');
        $offset = $request->input('offset');
        if(!$offset) {
            $offset = 0;
        }

        if(!empty($city)) {
            $res = $this->searchGeo($city);

            if(count($res['results']) > 0) {
                $lat = $res['results'][0]['geometry']['location']['lat'];
                $lng = $res['results'][0]['geometry']['location']['lng'];
            }
        }elseif(!empty($lat) && !empty($lng) ) {
            $res = $this->searchGeo($lat.','.$lng);

            if(count($res['results']) > 0) {
                $city = $res['results'][0]['formatted_address'];
            }
        }else {
            
            $lat = '-3.7153087';
            $lng = '-38.5766496';
            $city = 'Fortaleza';
        }
        
        $barbers = Barber::select(Barber::raw('*, SQRT(
            POW(69.1 * (latitude - '.$lat.'), 2) +
            POW(69.1 * ('.$lng.' - longitude) * COS(latitude / 57.3), 2)) AS distance'))
            ->orderBy('distance', 'ASC')
            ->offset($offset)
            ->limit(5)
            ->get();

        foreach($barbers as $bkey => $bvalue) {
            $barbers[$bkey]['avatar'] = url('media/avatars/'.$barbers[$bkey]['avatar']);
        }

        $array['data'] = $barbers;
        $array['loc'] = 'Fortaleza';
        
        return $array;
    }



    
    public function one($id) {
        $array = ['error' => ''];
        
        $barber = Barber::find($id);

        if($barber) {
            $barber['avatar'] = url('media/avatar/'.$barber['avatar']);
            $barber['favorited'] = false;
            $barber['photos'] = [];
            $barber['services'] = [];
            $barber['testimonials'] = [];
            $barber['available'] = [];

            $cFavorite = UserFavorite::where('id_user', $this->loggedUser->id)
            ->where('id_barber', $barber->id)
            ->count();
            if($cFavorite > 0) {
                $barber['favorited'] = true;
            }

            //Pegando as fotos do barbeiro
            $barber['photos'] = BarberPhotos::select(['id', 'url'])->where('id_barber', $barber->id)->get();
            foreach($barber['photos'] as $bpkey => $bpvalue) {
                $barber['photos'][$bpkey]['url'] = url('/uploads'.$barber['photos'][$bpkey]['url']);
            }

            //Pegando os serviços do barbeiro
            $barber['services'] = BarberServices::select(['id', 'name', 'price'])->where('id_barber', $barber->id)->get();

            //Pegando os testemunhos do barbeiro
            $barber['testimonials'] = BarberTestimonial::select(['id', 'name', 'rate', 'body'])->where('id_barber', $barber->id)->get();

            //Pegando a disponibilidade do barbeiro
            $availability = [];

            //Pegando a disponibilidade geral do barbeiro
            $avails = BarberAvailability::where('id_barber', $barber->id)->get();
            $availWeekdays = [];
            foreach($avails as $item) {
                $availWeekdays[$item['weekday']] = explode(',', $item['hours']);
            }

            //Pegando os agendamentos dos próximos 20 dias
            $appointments = [];
            $appQuery = UserAppointment::where('id_barber', $barber->id)
            ->whereBetween('ap_datetime', [
                date('Y-m-d').'00:00:00',
                date('Y-m-d', strtotime('+20 days')).'23:59:59'
            ])
            ->get();
            foreach($appQuery as $appItem) {
                $appointments[] = $appItem['ap_datetime'];
            }

            //Gerar disponibilidade 
            for($q=0;$q<20;$q++) {
                $timeItem = strtotime('+'.$q.' days');
                $weekday = date('w', $timeItem);

                if(in_array($weekday, array_keys($availWeekdays))) {
                    $hours = [];

                    $dayItem = date('Y-m-d', $timeItem);

                    foreach($availWeekdays[$weekday] as $hourItem) {
                        $dayFormated = $dayItem.' '.$hourItem.':00';
                        if(!in_array($dayFormated, $appointments)) {
                            $hours[] = $hourItem;
                        }
                    }

                    if(count($hours) > 0) {
                        $availability[] = [
                            'date' => $dayItem,
                            'hours' => $hours
                        ];
                    }
                }
            }


            $barber['available'] = $availability;

            $array['data'] = $barber;
        }else {
            $array['error'] = 'Barbeiro não encontrado';
            return $array;
        }

        
        return $array;
    } 

    public function setAppointment($id, Request $request) {
        // service, year, month, day, hour
        $array = ['error'=>''];

        $service = $request->input('service');
        $year = intval($request->input('year'));
        $month = intval($request->input('month'));
        $day = intval($request->input('day') + 1);
        $hour = intval($request->input('hour'));

        $month = ($month < 10) ? '0'.$month : $month;
        $day = ($day < 10) ? '0'.$day : $day;
        $hour = ($hour < 10) ? '0'.$hour : $hour;

        // 1. verificar se o serviço do barbeiro existe
        $barberservice = BarberServices::select()
            ->where('id', $service)
            ->where('id_barber', $id)
        ->first();

        if($barberservice) {
            // 2. verificar se a data é real
            $apDate = $year.'-'.$month.'-'.$day.' '.$hour.':00:00';
            if(strtotime($apDate) > 0) {
                // 3. verificar se o barbeiro já possui agendamento neste dia/hora
                $apps = UserAppointment::select()
                    ->where('id_barber', $id)
                    ->where('ap_datetime', $apDate)
                ->count();
                if($apps === 0) {
                    // 4.1 verificar se o barbeiro atende nesta data
                    $weekday = date('w', strtotime($apDate));
                    $avail = BarberAvailability::select()
                        ->where('id_barber', $id)
                        ->where('weekday', $weekday)
                    ->first();
                    if($avail) {
                        // 4.2 verificar se o barbeiro atende nesta hora
                        $hours = explode(',', $avail['hours']);
                        if(in_array($hour.':00', $hours)) {
                            // 5. fazer o agendamento
                            $newApp = new UserAppointment();
                            $newApp->id_user = $this->loggedUser->id;
                            $newApp->id_barber = $id;
                            $newApp->id_service = $service;
                            $newApp->ap_datetime = $apDate;
                            $newApp->save();
                        } else {
                            $array['error'] = 'Barbeiro não atende nesta hora';
                        }
                    } else {
                        $array['error'] = 'Barbeiro não atende neste dia';
                    }                    
                } else {
                    $array['error'] = 'Barbeiro já possui agendamento neste dia/hora';
                }
            } else {
                $array['error'] = 'Data inválida';
            }
        } else {
            $array['error'] = 'Serviço inexistente!';
        }
        return $array;
    }

    public function search(Request $request) {
        $array = ['error'=>'', 'list'=>[]];

        $q = $request->input('q');

        if($q) {

            $barbers = Barber::select()
                ->where('name', 'LIKE', '%'.$q.'%')
            ->get();

            foreach($barbers as $bkey => $barber) {
                $barbers[$bkey]['avatar'] = url('media/avatars/'.$barbers[$bkey]['avatar']);
            }

            $array['list'] = $barbers;
        } else {
            $array['error'] = 'Digite algo para buscar';
        }

        return $array;
    }
}

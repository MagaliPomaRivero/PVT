<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserForm;
use App\User;
use App\Role;
use Ldap;

class UserController extends Controller
{
    /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
    public function index(Request $request)
    {
        $users = User::query();
        if ($request->has('active')) {
            $users = $users->whereActive(filter_var($request->active, FILTER_VALIDATE_BOOLEAN), true);
        }
        if ($request->has('search')) {
            if ($request->search != 'null' && $request->search != '') {
                $search = $request->search;
                $users = $users->where(function ($query) use ($search) {
                    foreach (Schema::getColumnListing(User::getTableName()) as $column) {
                        $query = $query->orWhere($column, 'ilike', '%' . $search . '%');
                    }
                });
            }
        }
        if ($request->has('sortBy')) {
            if (count($request->sortBy) > 0 && count($request->sortDesc) > 0) {
                foreach ($request->sortBy as $i => $sort) {
                    $users = $users->orderBy($sort, filter_var($request->sortDesc[$i], FILTER_VALIDATE_BOOLEAN) ? 'desc' : 'asc');
                }
            }
        }
        return $users->paginate($request->input('per_page') ?? 10);
    }

    /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
    public function store(UserForm $request)
    {
        if (env("LDAP_AUTHENTICATION")) {
            $ldap = new Ldap();
            if (is_null($ldap->get_entry($request->username, 'uid'))) {
                abort(404);
            }
        }
        return User::create($request->all());
    }

    /**
    * Display the specified resource.
    *
    * @param  \App\User  $user
    * @return \Illuminate\Http\Response
    */
    public function show($id)
    {
        return User::findOrFail($id);
    }

    /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\User  $user
    * @return \Illuminate\Http\Response
    */
    public function update(UserForm $request, $id)
    {
        $user = User::findOrFail($id);
        if ($request->has('password') && !env("LDAP_AUTHENTICATION")) {
            if ($request->has('old_password')) {
                if (!(Auth::user()->id == $id && Hash::check($request->input('old_password'), $user->password))) {
                    return response()->json([
                        'message' => 'Invalid',
                        'errors' => [
                            'type' => ['Contraseña anterior incorrecta'],
                        ],
                    ], 409);
                }
            } else {
                unset($request['password']);
            }
        }
        $user->fill($request->all());
        $user->save();
        return $user;
    }

    /**
    * Remove the specified resource from storage.
    *
    * @param  \App\User  $user
    * @return \Illuminate\Http\Response
    */
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        if ($user->has_records()) {
            return response()->json([
				'message' => 'Forbidden',
				'errors' => [
					'type' => ['El usuario aún tiene acciones registradas'],
				]
			], 403);
        } else {
            $user->delete();
            return $user;
        }
    }

    public function get_roles($id)
    {
        $user = User::findOrFail($id);
        return $user->roles()->pluck('role_id');
    }

    public function set_roles(Request $request, $id)
    {
        foreach ($request->roles as $role) {
            Role::findOrFail($role);
        }
        $user = User::findOrFail($id);
        $user->syncRoles($request->roles);
        return $user->roles()->pluck('role_id');
    }

    public function unregistered_users()
    {
        $ldap = new Ldap();
        $unregistered_users = collect($ldap->list_entries())->pluck('uid')->diff(User::get()->pluck('username')->all());
        $items = [];
        foreach($unregistered_users as $user) {
            $item = $ldap->get_entry($user, 'uid');
            if (!is_null($item)) {
                array_push($items, $item);
            }
        }
        return response()->json(collect($items)->sortBy('sn')->values());
    }
}

<?php

namespace App\Controllers;

class StoreController extends BaseController
{
    public function index(): string
    {
        return view('store/home', [
            'store'      => config('Store'),
            'categories' => [
                ['slug' => 'casa', 'name' => 'Casa e organização', 'text' => 'Utensílios, potes e organizadores para o dia a dia.'],
                ['slug' => 'eletronicos', 'name' => 'Eletrônicos', 'text' => 'Fones, carregadores, caixas de som e acessórios.'],
                ['slug' => 'ferramentas', 'name' => 'Ferramentas', 'text' => 'Kits, medidores e ferramentas para pequenos reparos.'],
                ['slug' => 'beleza', 'name' => 'Beleza e cuidados', 'text' => 'Itens de cuidado pessoal, escovas e secadores.'],
                ['slug' => 'pet', 'name' => 'Pet', 'text' => 'Comedouros, caminhas e brinquedos para o seu pet.'],
                ['slug' => 'esportes', 'name' => 'Esportes e bem-estar', 'text' => 'Garrafas, halteres, tapetes e acessórios fitness.'],
            ],
        ]);
    }

    public function returns(): string
    {
        return view('store/returns', ['store' => config('Store'), 'pageTitle' => 'Trocas e devoluções']);
    }

    public function privacy(): string
    {
        return view('store/privacy', ['store' => config('Store'), 'pageTitle' => 'Política de privacidade']);
    }
}

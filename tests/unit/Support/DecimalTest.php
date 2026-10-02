<?php

namespace Tests\Unit\Support;

use App\Support\Decimal;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DecimalTest extends CIUnitTestCase
{
    public static function validValues(): array
    {
        return [
            'ponto decimal'             => ['1234.56', '1234.56'],
            'vírgula decimal'           => ['1234,56', '1234.56'],
            'milhar BR'                 => ['1.234,56', '1234.56'],
            'milhar US'                 => ['1,234.56', '1234.56'],
            'com símbolo'               => ['R$ 49,90', '49.90'],
            'inteiro'                   => ['100', '100.00'],
            'int nativo'                => [100, '100.00'],
            'arredonda para cima'       => ['10.005', '10.01'],
            'arredonda para baixo'      => ['10.004', '10.00'],
            'arredonda com vai-um'      => ['9.999', '10.00'],
            'milhar sem decimais'       => ['1.234.567', '1234567.00'],
            'negativo'                  => ['-5,5', '-5.50'],
            'espaços'                   => ['  12,30 ', '12.30'],
        ];
    }

    #[DataProvider('validValues')]
    public function testNormalizesValidValues(mixed $input, string $expected): void
    {
        $this->assertSame($expected, Decimal::normalize($input));
    }

    public static function invalidValues(): array
    {
        return [
            'vazio'             => [''],
            'nulo'              => [null],
            'texto'             => ['abc'],
            'duas vírgulas'     => ['1,2,3'],
            'misturado'         => ['12a,30'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testReturnsNullForInvalidValues(mixed $input): void
    {
        $this->assertNull(Decimal::normalize($input));
    }

    public function testRespectsScale(): void
    {
        $this->assertSame('0.150', Decimal::normalize('0,15', 3));
        $this->assertSame('3', Decimal::normalize('2.5', 0));
    }
}

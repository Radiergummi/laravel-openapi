<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The trailing return sits past the hundred-statement backstop, so the static read gives up rather
 * than scanning an unbounded body.
 */
class BeyondBackstopFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('action') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        $s1 = 1;
        $s2 = 2;
        $s3 = 3;
        $s4 = 4;
        $s5 = 5;
        $s6 = 6;
        $s7 = 7;
        $s8 = 8;
        $s9 = 9;
        $s10 = 10;
        $s11 = 11;
        $s12 = 12;
        $s13 = 13;
        $s14 = 14;
        $s15 = 15;
        $s16 = 16;
        $s17 = 17;
        $s18 = 18;
        $s19 = 19;
        $s20 = 20;
        $s21 = 21;
        $s22 = 22;
        $s23 = 23;
        $s24 = 24;
        $s25 = 25;
        $s26 = 26;
        $s27 = 27;
        $s28 = 28;
        $s29 = 29;
        $s30 = 30;
        $s31 = 31;
        $s32 = 32;
        $s33 = 33;
        $s34 = 34;
        $s35 = 35;
        $s36 = 36;
        $s37 = 37;
        $s38 = 38;
        $s39 = 39;
        $s40 = 40;
        $s41 = 41;
        $s42 = 42;
        $s43 = 43;
        $s44 = 44;
        $s45 = 45;
        $s46 = 46;
        $s47 = 47;
        $s48 = 48;
        $s49 = 49;
        $s50 = 50;
        $s51 = 51;
        $s52 = 52;
        $s53 = 53;
        $s54 = 54;
        $s55 = 55;
        $s56 = 56;
        $s57 = 57;
        $s58 = 58;
        $s59 = 59;
        $s60 = 60;
        $s61 = 61;
        $s62 = 62;
        $s63 = 63;
        $s64 = 64;
        $s65 = 65;
        $s66 = 66;
        $s67 = 67;
        $s68 = 68;
        $s69 = 69;
        $s70 = 70;
        $s71 = 71;
        $s72 = 72;
        $s73 = 73;
        $s74 = 74;
        $s75 = 75;
        $s76 = 76;
        $s77 = 77;
        $s78 = 78;
        $s79 = 79;
        $s80 = 80;
        $s81 = 81;
        $s82 = 82;
        $s83 = 83;
        $s84 = 84;
        $s85 = 85;
        $s86 = 86;
        $s87 = 87;
        $s88 = 88;
        $s89 = 89;
        $s90 = 90;
        $s91 = 91;
        $s92 = 92;
        $s93 = 93;
        $s94 = 94;
        $s95 = 95;
        $s96 = 96;
        $s97 = 97;
        $s98 = 98;
        $s99 = 99;
        $s100 = 100;

        return ['name' => 'required|string'];
    }
}

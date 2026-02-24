<?php
/**
 * Visitor naming — assigns a persistent human name to each unique visitor.
 *
 * 465 curated international names, then compound names (adjective + name)
 * for ~14,000 more. After that, compounds cycle with Roman numeral suffixes.
 */

declare(strict_types=1);

/**
 * Curated name pool — roughly balanced across regions and genders.
 */
const VISITOR_NAMES = [
    // East Asian
    'Yuki', 'Haruto', 'Mei', 'Ren', 'Sakura', 'Kaito', 'Hana', 'Sora',
    'Aoi', 'Riku', 'Mio', 'Yuto', 'Akari', 'Hinata', 'Kento', 'Nao',
    'Jia', 'Wei', 'Lian', 'Zhi', 'Xiu', 'Hao', 'Fang', 'Qing',
    'Bao', 'Hua', 'Ming', 'Tao', 'Lan', 'Shu', 'Chen', 'Yu',
    'Min-jun', 'Seo-yeon', 'Ji-ho', 'Ha-eun', 'Yeon', 'Dae', 'Suji', 'Hwan',
    'Haru', 'Natsuki', 'Koharu', 'Takumi', 'Yuna', 'Shinji', 'Emi', 'Kenji',
    'Xiao', 'Ling', 'Jun', 'Yue', 'Feng', 'Rui', 'Ning', 'Pei',
    'Eun', 'Joon', 'Minji', 'Seung', 'Hye', 'Taeho', 'Sorin', 'Nari',

    // South Asian
    'Arjun', 'Priya', 'Devi', 'Kiran', 'Asha', 'Ravi', 'Ananya', 'Vikram',
    'Lila', 'Sanjay', 'Kavya', 'Neel', 'Meera', 'Aarav', 'Rohan', 'Amara',
    'Zara', 'Kabir', 'Isha', 'Advait', 'Tara', 'Vivaan', 'Saanvi', 'Ishan',
    'Nisha', 'Arun', 'Leela', 'Dhruv', 'Sita', 'Veer', 'Riya', 'Surya',
    'Nurul', 'Aisha', 'Farhan', 'Ayesha', 'Imran', 'Fatima', 'Bilal', 'Hira',
    'Anika', 'Harsh', 'Pooja', 'Aman', 'Tanvi', 'Nikhil', 'Shreya', 'Arnav',
    'Lakshmi', 'Siddharth', 'Gauri', 'Yash', 'Swara', 'Pranav', 'Kiara', 'Dev',

    // African
    'Amina', 'Kwame', 'Zuri', 'Jelani', 'Nia', 'Kofi', 'Adaeze', 'Tendai',
    'Chidi', 'Imani', 'Emeka', 'Nala', 'Obinna', 'Sanaa', 'Jabari',
    'Wanjiku', 'Sekou', 'Ayo', 'Chiamaka', 'Folami', 'Uzoma', 'Abena', 'Idris',
    'Makena', 'Tau', 'Hadiza', 'Mensah', 'Adama', 'Kojo', 'Zahra', 'Bakari',
    'Lindiwe', 'Thabo', 'Naledi', 'Sipho', 'Nomsa', 'Bongani', 'Thandiwe', 'Dumisa',
    'Ife', 'Oluwole', 'Ayana', 'Chike', 'Femi', 'Ngozi', 'Udo', 'Zola',
    'Amadi', 'Eshe', 'Khari', 'Olu', 'Sade', 'Wole', 'Yemi', 'Nneka',
    'Dalila', 'Kamau', 'Lewa', 'Mosi', 'Nyah', 'Otieno', 'Rehema', 'Sefu',

    // European (Western / Northern / Southern)
    'Emma', 'Luca', 'Sofia', 'Felix', 'Clara', 'Matteo', 'Elena', 'Hugo',
    'Astrid', 'Anton', 'Elsa', 'Lars', 'Freya', 'Finn', 'Ingrid', 'Sven',
    'Isla', 'Ewan', 'Maeve', 'Ciaran', 'Niamh', 'Declan', 'Sinead', 'Ronan',
    'Chiara', 'Marco', 'Lucia', 'Dante', 'Valentina', 'Paolo', 'Marta', 'Enzo',
    'Anaïs', 'Jules', 'Camille', 'Rémi', 'Léa', 'Théo', 'Inès', 'Noé',
    'Nora', 'Erik', 'Sigrid', 'Axel', 'Saga', 'Viggo', 'Tuva', 'Leif',
    'Alma', 'Oskar', 'Linnea', 'Bjorn', 'Elodie', 'Bastien', 'Cosima', 'Ludo',
    'Ailsa', 'Callum', 'Orla', 'Lachlan', 'Grainne', 'Eamon', 'Aisling', 'Bram',

    // European (Eastern / Central)
    'Mila', 'Nikola', 'Petra', 'Aleksei', 'Katya', 'Yuri', 'Nadia', 'Dmitri',
    'Ivana', 'Marek', 'Zuzana', 'Tomasz', 'Alicja', 'Karel', 'Daria', 'Vlad',
    'Kasia', 'Lukasz', 'Jana', 'Stefan', 'Maja', 'Branko', 'Vesna', 'Goran',
    'Anya', 'Boris', 'Irina', 'Lev', 'Milena', 'Pavel', 'Tatiana', 'Sasha',
    'Ewa', 'Jakub', 'Lenka', 'Ondrej', 'Roza', 'Viktor', 'Zora', 'Milan',

    // Latin American / Iberian
    'Camila', 'Santiago', 'Isabella', 'Joaquín', 'Luna', 'Emilio',
    'Ximena', 'Alejandro', 'Sofía', 'Diego', 'Luciana', 'Rafael', 'Paloma', 'Andrés',
    'Catalina', 'Tomás', 'Daniela', 'León', 'Marisol', 'Carlos', 'Gabriel',
    'Renata', 'Ignacio', 'Fernanda', 'Sebastián', 'Carmen', 'Manuel', 'Pilar',
    'Esperanza', 'Esteban', 'Luz', 'Ramón', 'Soledad', 'Matías', 'Celeste', 'Martín',
    'Isadora', 'Thiago', 'Lara', 'Nico', 'Bianca', 'Héctor', 'Dulce', 'Iván',

    // Middle Eastern / Central Asian
    'Layla', 'Omar', 'Dina', 'Yusuf', 'Noor', 'Tariq', 'Samira', 'Karim',
    'Yasmin', 'Hassan', 'Leyla', 'Ali', 'Rania', 'Khalid', 'Maryam', 'Samir',
    'Aylin', 'Emre', 'Elif', 'Kerem', 'Naz', 'Baran', 'Dilara', 'Serkan',
    'Anar', 'Gulnara', 'Timur', 'Asel', 'Nurlan', 'Aida', 'Rustam', 'Sevara',
    'Dara', 'Farid', 'Jaleh', 'Navid', 'Janan', 'Lina', 'Mazin', 'Nadira',
    'Rami', 'Selim', 'Soraya', 'Zayn', 'Amira', 'Ehsan', 'Hadi', 'Parisa',

    // Pacific / Southeast Asian
    'Aroha', 'Tane', 'Moana', 'Koa', 'Leilani', 'Malia', 'Keanu',
    'Maui', 'Aria', 'Siti', 'Rizal', 'Putri', 'Anong', 'Lek', 'Chai',
    'Linh', 'Duc', 'Thuy', 'An', 'Mai', 'Phong', 'Hien',
    'Manu', 'Tia', 'Hemi', 'Ngaire', 'Wiremu', 'Anahera', 'Rawiri', 'Mere',
    'Dalisay', 'Bayani', 'Amihan', 'Tala', 'Dakila', 'Hiraya', 'Mayumi', 'Akira',

    // Gender-neutral / Pan-cultural
    'River', 'Sky', 'Sage', 'Ash', 'Rowan', 'Avery', 'Quinn', 'Remy',
    'Indigo', 'Sol', 'Paz', 'Eden', 'Nova', 'Kai', 'Shay', 'Zen',
    'Rio', 'Mika', 'Yael', 'Arin', 'Rune', 'Ember', 'Vale', 'Onyx',
    'Jade', 'Atlas', 'Wren', 'Cleo', 'Bodhi', 'Zephyr', 'Lyric', 'Orion',
    'Finch', 'Lark', 'Moss', 'Seren', 'Bryn', 'Cove', 'Fern', 'Glen',
    'Haze', 'Birch', 'Dune', 'Reef', 'Storm', 'Cliff', 'Brook', 'Flint',
];

/**
 * Adjectives for compound names — evocative, gentle, thematic.
 * Used after the bare name pool is exhausted.
 */
const VISITOR_ADJECTIVES = [
    'Quiet', 'Distant', 'Gentle', 'Patient', 'Restless',
    'Curious', 'Silent', 'Steady', 'Drifting', 'Passing',
    'Returning', 'Listening', 'Seeking', 'Watching', 'Waiting',
    'Fading', 'Glowing', 'Dreaming', 'Waking', 'Roaming',
    'Still', 'Swift', 'Lone', 'Bright', 'Pale',
    'Hushed', 'Hidden', 'Hollow', 'Wandering', 'Tireless',
];

/**
 * Return the name for the Nth visitor (0-indexed position).
 *
 * Phase 1: Bare names from the pool ("Yuki", "Kwame", ...).
 * Phase 2: Compound names — adjective + name ("Quiet Yuki", "Wandering Kwame", ...).
 * Phase 3: Compounds cycle with Roman numerals ("Quiet Yuki II", ...).
 */
function assign_visitor_name(int $position): string
{
    $names     = count(VISITOR_NAMES);
    $adjectives = count(VISITOR_ADJECTIVES);

    // Phase 1: bare names
    if ($position < $names) {
        return VISITOR_NAMES[$position];
    }

    // Phase 2+: compound names (adjective + name), cycling with Roman numerals
    $compound_pos  = $position - $names;
    $compound_size = $adjectives * $names;
    $cycle         = intdiv($compound_pos, $compound_size);
    $offset        = $compound_pos % $compound_size;
    $adj           = VISITOR_ADJECTIVES[intdiv($offset, $names)];
    $name          = VISITOR_NAMES[$offset % $names];

    $result = $adj . ' ' . $name;
    if ($cycle > 0) {
        $result .= ' ' . to_roman($cycle + 1);
    }

    return $result;
}

/**
 * Convert an integer to a Roman numeral string (1–3999).
 */
function to_roman(int $n): string
{
    if ($n < 1 || $n > 3999) {
        return (string) $n;
    }

    $map = [
        1000 => 'M',  900 => 'CM', 500 => 'D',  400 => 'CD',
        100  => 'C',  90  => 'XC', 50  => 'L',   40  => 'XL',
        10   => 'X',  9   => 'IX', 5   => 'V',    4  => 'IV',
        1    => 'I',
    ];

    $result = '';
    foreach ($map as $value => $numeral) {
        while ($n >= $value) {
            $result .= $numeral;
            $n -= $value;
        }
    }
    return $result;
}

<?php
/**
 * Visitor naming — assigns a persistent human name to each unique visitor.
 *
 * ~800 curated international names. When names run out, cycles with Roman
 * numeral suffixes: "Yuki II", "Yuki III", etc.
 */

declare(strict_types=1);

/**
 * Curated name pool — roughly balanced across regions and genders.
 */
const VISITOR_NAMES = [
    // East Asian — Japanese
    'Yuki', 'Haruto', 'Mei', 'Ren', 'Sakura', 'Kaito', 'Hana', 'Sora',
    'Aoi', 'Riku', 'Mio', 'Yuto', 'Akari', 'Hinata', 'Kento', 'Nao',
    'Yuna', 'Taro', 'Koharu', 'Shion', 'Misaki', 'Hayato', 'Nanami', 'Itsuki',
    'Hikari', 'Sota', 'Ayumi', 'Daiki', 'Honoka', 'Ryo', 'Chihiro', 'Takumi',

    // East Asian — Chinese
    'Jia', 'Wei', 'Lian', 'Zhi', 'Xiu', 'Hao', 'Fang', 'Qing',
    'Bao', 'Hua', 'Ming', 'Tao', 'Lan', 'Shu', 'Chen', 'Yu',
    'Xia', 'Jun', 'Ying', 'Lei', 'Rong', 'Peng', 'Wen', 'Ning',
    'Yan', 'Hong', 'Feng', 'Lin', 'Mei-Ling', 'Bo', 'Xin', 'Hui',

    // East Asian — Korean
    'Min-jun', 'Seo-yeon', 'Ji-ho', 'Ha-eun', 'Yeon', 'Dae', 'Suji', 'Hwan',
    'Jisoo', 'Tae-hyun', 'Eun-bi', 'Dong-woo', 'So-hee', 'Hyun', 'Yoo-jin', 'Sung',
    'Na-rae', 'Woo-jin', 'Chae-won', 'Jun-seo', 'Mi-ran', 'Kyung', 'Hye-jin', 'Seung',

    // South Asian — Indian
    'Arjun', 'Priya', 'Devi', 'Kiran', 'Asha', 'Ravi', 'Ananya', 'Vikram',
    'Lila', 'Sanjay', 'Kavya', 'Neel', 'Meera', 'Aarav', 'Rohan', 'Amara',
    'Zara', 'Kabir', 'Isha', 'Advait', 'Tara', 'Vivaan', 'Saanvi', 'Ishan',
    'Nisha', 'Arun', 'Leela', 'Dhruv', 'Sita', 'Veer', 'Riya', 'Surya',
    'Pooja', 'Rahul', 'Deepa', 'Amit', 'Lakshmi', 'Manish', 'Divya', 'Nitin',
    'Sneha', 'Harsh', 'Anjali', 'Gaurav', 'Radha', 'Aditya', 'Sonal', 'Varun',
    'Neha', 'Kunal', 'Swati', 'Pranav', 'Jaya', 'Siddharth', 'Uma', 'Dev',

    // South Asian — Pakistani / Bangladeshi
    'Nurul', 'Aisha', 'Farhan', 'Ayesha', 'Imran', 'Fatima', 'Bilal', 'Hira',
    'Shahid', 'Rabia', 'Usman', 'Sadia', 'Asad', 'Mehreen', 'Hamza', 'Bushra',
    'Zain', 'Nadia', 'Junaid', 'Rubina', 'Saad', 'Maleeka', 'Rizwan', 'Tahira',

    // African — West
    'Amina', 'Kwame', 'Zuri', 'Jelani', 'Nia', 'Kofi', 'Adaeze', 'Tendai',
    'Chidi', 'Imani', 'Emeka', 'Nala', 'Obinna', 'Sanaa', 'Jabari',
    'Wanjiku', 'Sekou', 'Ayo', 'Chiamaka', 'Folami', 'Uzoma', 'Abena', 'Idris',
    'Adama', 'Kojo', 'Zahra', 'Bakari',
    'Akin', 'Yemi', 'Olu', 'Ngozi', 'Chika', 'Femi', 'Binta', 'Ade',
    'Ifeoma', 'Taiwo', 'Kehinde', 'Amadi', 'Obi', 'Efua', 'Kwesi', 'Ama',

    // African — East / South
    'Makena', 'Tau', 'Hadiza', 'Mensah',
    'Lindiwe', 'Thabo', 'Naledi', 'Sipho', 'Nomsa', 'Bongani', 'Thandiwe', 'Dumisa',
    'Zawadi', 'Baraka', 'Amani', 'Faraji', 'Rehema', 'Juma', 'Neema', 'Daudi',
    'Eshe', 'Mandla', 'Nandi', 'Zola', 'Sibusiso', 'Precious', 'Lerato', 'Tshepo',
    'Mpho', 'Dineo', 'Kagiso', 'Lesedi', 'Palesa', 'Themba', 'Zanele', 'Lwazi',

    // European — Western
    'Emma', 'Luca', 'Sofia', 'Felix', 'Clara', 'Matteo', 'Elena', 'Hugo',
    'Charlotte', 'Arthur', 'Alice', 'Oscar', 'Louise', 'Louis', 'Marie', 'Thomas',
    'Anna', 'Max', 'Sophie', 'Leo', 'Julia', 'Paul', 'Hannah', 'David',
    'Sarah', 'Tim', 'Eva', 'Jan', 'Lisa', 'Simon', 'Laura', 'Nick',

    // European — Northern / Scandinavian
    'Astrid', 'Anton', 'Elsa', 'Lars', 'Freya', 'Finn', 'Ingrid', 'Sven',
    'Nora', 'Erik', 'Sigrid', 'Axel', 'Saga', 'Viggo', 'Tuva', 'Leif',
    'Torsten', 'Liv', 'Bjorn', 'Freja', 'Gunnar', 'Hedda', 'Ragnar', 'Tyra',
    'Arvid', 'Ebba', 'Ivar', 'Solveig', 'Stellan', 'Thyra', 'Odin', 'Maja',

    // European — Irish / British
    'Isla', 'Ewan', 'Maeve', 'Ciaran', 'Niamh', 'Declan', 'Sinead', 'Ronan',
    'Aoife', 'Cormac', 'Saoirse', 'Padraig', 'Siobhan', 'Eamon', 'Aisling', 'Lorcan',
    'Fiona', 'Callum', 'Eileen', 'Hamish', 'Moira', 'Angus', 'Grainne', 'Liam',

    // European — Italian
    'Chiara', 'Marco', 'Lucia', 'Dante', 'Valentina', 'Paolo', 'Marta', 'Enzo',
    'Giulia', 'Alessandro', 'Francesca', 'Lorenzo', 'Bianca', 'Stefano', 'Rosa', 'Andrea',
    'Eleonora', 'Tommaso', 'Serena', 'Gianni', 'Silvia', 'Roberto', 'Viola', 'Fabio',

    // European — French
    'Anaïs', 'Jules', 'Camille', 'Rémi', 'Léa', 'Théo', 'Inès', 'Noé',
    'Manon', 'Baptiste', 'Adèle', 'Bastien', 'Clémentine', 'Raphaël', 'Margot', 'Gabin',
    'Hélène', 'Lucien', 'Mathilde', 'Aurélien', 'Colette', 'Maxime', 'Sylvie', 'Pierre',

    // European — Eastern / Central
    'Mila', 'Nikola', 'Petra', 'Aleksei', 'Katya', 'Yuri', 'Dmitri',
    'Ivana', 'Marek', 'Zuzana', 'Tomasz', 'Alicja', 'Karel', 'Daria', 'Vlad',
    'Kasia', 'Lukasz', 'Jana', 'Stefan', 'Branko', 'Vesna', 'Goran',
    'Natasha', 'Boris', 'Olga', 'Pavel', 'Irina', 'Sergei', 'Anya', 'Viktor',
    'Tatiana', 'Mikhail', 'Svetlana', 'Igor', 'Lydia', 'Andrei', 'Elena', 'Oleg',
    'Milena', 'Stanislav', 'Lenka', 'Radek', 'Hana', 'Vojtech', 'Klara', 'Ondrej',
    'Agnieszka', 'Jakub', 'Wanda', 'Krzysztof', 'Dorota', 'Zbigniew', 'Ewa', 'Piotr',

    // European — Greek / Balkan
    'Eleni', 'Nikos', 'Athena', 'Kostas', 'Dimitra', 'Alexandros', 'Io', 'Yannis',
    'Kalliope', 'Stavros', 'Thalia', 'Vassilis', 'Ariadne', 'Spiros', 'Daphne', 'Petros',
    'Mirjana', 'Dragan', 'Jelena', 'Zoran', 'Ivanka', 'Dejan', 'Snezana', 'Milos',

    // Latin American / Iberian
    'Camila', 'Santiago', 'Isabella', 'Joaquín', 'Luna', 'Emilio',
    'Ximena', 'Alejandro', 'Sofía', 'Diego', 'Luciana', 'Rafael', 'Paloma', 'Andrés',
    'Catalina', 'Tomás', 'Daniela', 'León', 'Marisol', 'Carlos', 'Inés', 'Gabriel',
    'Renata', 'Ignacio', 'Fernanda', 'Sebastián', 'Carmen', 'Manuel', 'Pilar', 'Jesús',
    'Mariana', 'Miguel', 'Dulce', 'Ramón', 'Esperanza', 'Arturo', 'Luz', 'Ernesto',
    'Verónica', 'Rodrigo', 'Natalia', 'Hernán', 'Lorena', 'Álvaro', 'Constanza', 'Felipe',
    'Isadora', 'Thiago', 'Beatriz', 'Caio', 'Letícia', 'Pedro', 'Larissa', 'Henrique',

    // Middle Eastern — Arabic
    'Layla', 'Omar', 'Dina', 'Yusuf', 'Noor', 'Tariq', 'Samira', 'Karim',
    'Yasmin', 'Hassan', 'Leyla', 'Ali', 'Rania', 'Khalid', 'Maryam', 'Samir',
    'Amira', 'Faisal', 'Dalal', 'Nasser', 'Huda', 'Ibrahim', 'Salma', 'Rashid',
    'Lubna', 'Walid', 'Ghada', 'Adel', 'Sawsan', 'Jamal', 'Rana', 'Mustafa',
    'Haneen', 'Bassam', 'Lina', 'Sami', 'Noura', 'Majid', 'Hayat', 'Tarek',

    // Middle Eastern — Turkish
    'Aylin', 'Emre', 'Elif', 'Kerem', 'Naz', 'Baran', 'Dilara', 'Serkan',
    'Ceren', 'Murat', 'Defne', 'Burak', 'Yasemin', 'Cem', 'Selin', 'Kaan',
    'Ezgi', 'Alp', 'Tulay', 'Deniz', 'Pinar', 'Arda', 'Melis', 'Tolga',

    // Middle Eastern — Persian / Central Asian
    'Anar', 'Gulnara', 'Timur', 'Asel', 'Nurlan', 'Aida', 'Rustam', 'Sevara',
    'Parisa', 'Darius', 'Shirin', 'Cyrus', 'Nasrin', 'Babak', 'Mina', 'Kaveh',
    'Azadeh', 'Farzad', 'Setareh', 'Arash', 'Golnar', 'Behzad', 'Roxana', 'Parviz',

    // Pacific — Polynesian / Maori
    'Aroha', 'Tane', 'Moana', 'Koa', 'Leilani', 'Malia', 'Keanu',
    'Maui', 'Aria', 'Tia', 'Wiremu', 'Hinerangi', 'Rawiri', 'Manaia', 'Ngaire',
    'Kahu', 'Mere', 'Rangi', 'Ataahua', 'Nikau', 'Kapua', 'Tamati', 'Anahera', 'Matiu',

    // Southeast Asian
    'Siti', 'Rizal', 'Putri', 'Anong', 'Lek', 'Chai',
    'Linh', 'Duc', 'Thuy', 'An', 'Mai', 'Phong', 'Hien',
    'Phuong', 'Minh', 'Lan', 'Tuan', 'Dao', 'Trung', 'Ngoc', 'Thanh',
    'Dara', 'Sokha', 'Chanthy', 'Vanna', 'Narith', 'Bopha', 'Sophea', 'Kosal',
    'Ayu', 'Wayan', 'Dewi', 'Bagus', 'Ni', 'Made', 'Ketut', 'Putu',

    // Gender-neutral / Pan-cultural
    'River', 'Sky', 'Sage', 'Ash', 'Rowan', 'Avery', 'Quinn', 'Remy',
    'Indigo', 'Sol', 'Paz', 'Eden', 'Nova', 'Kai', 'Shay', 'Zen',
    'Rio', 'Mika', 'Yael', 'Arin', 'Rune', 'Ember', 'Vale', 'Onyx',
    'Jade', 'Atlas', 'Wren', 'Cleo', 'Bodhi', 'Zephyr', 'Lyric', 'Orion',
    'Sable', 'Echo', 'Reed', 'Briar', 'Harbor', 'Marlowe', 'Lark', 'Cedar',
    'Finch', 'Frost', 'Glen', 'Haven', 'Oakley', 'Storm', 'Cypress', 'Sparrow',
];

/**
 * Return the name for the Nth visitor (0-indexed position).
 *
 * First ~800 visitors get a unique name. After that, names cycle with
 * Roman numeral suffixes: "Yuki II", "Yuki III", etc.
 */
function assign_visitor_name(int $position): string
{
    $pool_size = count(VISITOR_NAMES);
    $cycle     = intdiv($position, $pool_size); // 0 on first pass
    $index     = $position % $pool_size;
    $name      = VISITOR_NAMES[$index];

    if ($cycle > 0) {
        $name .= ' ' . to_roman($cycle + 1);
    }

    return $name;
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

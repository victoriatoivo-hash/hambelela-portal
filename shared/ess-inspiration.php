<?php
declare(strict_types=1);

/** Curated short excerpts. KJV verses; literary quotes retain their source URL.
 * Add entries to the independent arrays; keep quotes brief and check attribution
 * against the linked original work rather than quote aggregation websites.
 */
function ess_inspiration_catalog(): array
{
    static $catalog;
    if ($catalog !== null) { return $catalog; }
    $verses = [
        ['And whatsoever ye do, do it heartily, as to the Lord, and not unto men;', 'Colossians 3:23'],
        ['Let all your things be done with charity.', '1 Corinthians 16:14'],
        ['Commit thy works unto the LORD, and thy thoughts shall be established.', 'Proverbs 16:3'],
        ['A soft answer turneth away wrath: but grievous words stir up anger.', 'Proverbs 15:1'],
        ['Iron sharpeneth iron; so a man sharpeneth the countenance of his friend.', 'Proverbs 27:17'],
        ['The integrity of the upright shall guide them:', 'Proverbs 11:3'],
        ['The hand of the diligent shall bear rule:', 'Proverbs 12:24'],
        ['In all labour there is profit:', 'Proverbs 14:23'],
        ['The thoughts of the diligent tend only to plenteousness;', 'Proverbs 21:5'],
        ['He that walketh uprightly walketh surely:', 'Proverbs 10:9'],
        ['A good name is rather to be chosen than great riches, and loving favour rather than silver and gold.', 'Proverbs 22:1'],
        ['With the ancient is wisdom; and in length of days understanding.', 'Job 12:12'],
        ['A faithful man shall abound with blessings:', 'Proverbs 28:20'],
        ['In the multitude of counsellors there is safety.', 'Proverbs 11:14'],
        ['The heart of the prudent getteth knowledge; and the ear of the wise seeketh knowledge.', 'Proverbs 18:15'],
        ['A wise man will hear, and will increase learning;', 'Proverbs 1:5'],
        ['Wisdom is the principal thing; therefore get wisdom:', 'Proverbs 4:7'],
        ['Happy is the man that findeth wisdom, and the man that getteth understanding.', 'Proverbs 3:13'],
        ['Better is the end of a thing than the beginning thereof:', 'Ecclesiastes 7:8'],
        ['To every thing there is a season, and a time to every purpose under the heaven:', 'Ecclesiastes 3:1'],
        ['Two are better than one; because they have a good reward for their labour.', 'Ecclesiastes 4:9'],
        ['Whatsoever thy hand findeth to do, do it with thy might;', 'Ecclesiastes 9:10'],
        ['Let us not be weary in well doing:', 'Galatians 6:9'],
        ['Bear ye one another’s burdens, and so fulfil the law of Christ.', 'Galatians 6:2'],
        ['Be ye kind one to another, tenderhearted, forgiving one another,', 'Ephesians 4:32'],
        ['With all lowliness and meekness, with longsuffering, forbearing one another in love;', 'Ephesians 4:2'],
        ['Let your moderation be known unto all men.', 'Philippians 4:5'],
        ['I can do all things through Christ which strengtheneth me.', 'Philippians 4:13'],
        ['Rejoice in the Lord alway: and again I say, Rejoice.', 'Philippians 4:4'],
        ['Prove all things; hold fast that which is good.', '1 Thessalonians 5:21'],
        ['Rejoice evermore.', '1 Thessalonians 5:16'],
        ['Pray without ceasing.', '1 Thessalonians 5:17'],
        ['In every thing give thanks:', '1 Thessalonians 5:18'],
        ['Comfort yourselves together, and edify one another,', '1 Thessalonians 5:11'],
        ['Be patient toward all men.', '1 Thessalonians 5:14'],
        ['Let every man be swift to hear, slow to speak, slow to wrath:', 'James 1:19'],
        ['But let patience have her perfect work,', 'James 1:4'],
        ['If any of you lack wisdom, let him ask of God,', 'James 1:5'],
        ['Be ye doers of the word, and not hearers only,', 'James 1:22'],
        ['The fruit of righteousness is sown in peace of them that make peace.', 'James 3:18'],
        ['Casting all your care upon him; for he careth for you.', '1 Peter 5:7'],
        ['Use hospitality one to another without grudging.', '1 Peter 4:9'],
        ['As every man hath received the gift, even so minister the same one to another,', '1 Peter 4:10'],
        ['Let us consider one another to provoke unto love and to good works:', 'Hebrews 10:24'],
        ['To do good and to communicate forget not:', 'Hebrews 13:16'],
        ['Let brotherly love continue.', 'Hebrews 13:1'],
        ['Be of good courage, and he shall strengthen your heart, all ye that hope in the LORD.', 'Psalm 31:24'],
        ['This is the day which the LORD hath made; we will rejoice and be glad in it.', 'Psalm 118:24'],
        ['Thy word is a lamp unto my feet, and a light unto my path.', 'Psalm 119:105'],
        ['God is our refuge and strength, a very present help in trouble.', 'Psalm 46:1'],
        ['The LORD is my shepherd; I shall not want.', 'Psalm 23:1'],
        ['He restoreth my soul:', 'Psalm 23:3'],
        ['Be still, and know that I am God:', 'Psalm 46:10'],
        ['Trust in the LORD with all thine heart;', 'Proverbs 3:5'],
        ['In all thy ways acknowledge him, and he shall direct thy paths.', 'Proverbs 3:6'],
        ['They that wait upon the LORD shall renew their strength;', 'Isaiah 40:31'],
        ['Blessed are the peacemakers: for they shall be called the children of God.', 'Matthew 5:9'],
        ['Let your light so shine before men, that they may see your good works,', 'Matthew 5:16'],
        ['As ye would that men should do to you, do ye also to them likewise.', 'Luke 6:31'],
        ['Be not overcome of evil, but overcome evil with good.', 'Romans 12:21'],
    ];
    $sources = [
        'allen' => ['James Allen', 'https://www.gutenberg.org/files/4507/4507-h/4507-h.htm'],
        'emerson' => ['Ralph Waldo Emerson', 'https://www.gutenberg.org/files/16643/16643-h/16643-h.htm'],
        'thoreau' => ['Henry David Thoreau', 'https://www.gutenberg.org/files/205/205-h/205-h.htm'],
    ];
    // Excerpts from As a Man Thinketh, Essays, and Walden (public-domain works).
    $quotes = [
        ['The will to do springs from the knowledge that we can do.', 'allen'],
        ['Dreams are the seedlings of realities.', 'allen'],
        ['The greatest achievement was at first and for a time a dream.', 'allen'],
        ['Dream lofty dreams, and as you dream, so shall you become.', 'allen'],
        ['Achievement, of whatever kind, is the crown of effort, the diadem of thought.', 'allen'],
        ['A man should conceive of a legitimate purpose in his heart, and set out to accomplish it.', 'allen'],
        ['He who cherishes a beautiful vision, a lofty ideal in his heart, will one day realize it.', 'allen'],
        ['The oak sleeps in the acorn; the bird waits in the egg;', 'allen'],
        ['He should make this purpose the centralizing point of his thoughts.', 'allen'],
        ['His every thought is allied with power, and all difficulties are bravely met and wisely overcome.', 'allen'],
        ['Spiritual achievements are the consummation of holy aspirations.', 'allen'],
        ['The world is beautiful because they have lived;', 'allen'],
        ['The dreamers are the saviours of the world.', 'allen'],
        ['He has become one with his Ideal.', 'allen'],
        ['Calmness of mind is one of the beautiful jewels of wisdom.', 'allen'],
        ['It is the result of long and patient effort in self-control.', 'allen'],
        ['The strong, calm man is always loved and revered.', 'allen'],
        ['Keep your hand firmly upon the helm of thought.', 'allen'],
        ['Say unto your heart, “Peace, be still!”', 'allen'],
        ['Good thoughts bear good fruit, bad thoughts bad fruit.', 'allen'],
        ['The day is always his who works in it with serenity and great aims.', 'emerson'],
        ['This time, like all times, is a very good one, if we but know what to do with it.', 'emerson'],
        ['A great soul will be strong to live, as well as strong to think.', 'emerson'],
        ['A great man is always willing to be little.', 'emerson'],
        ['Love, and you shall be loved.', 'emerson'],
        ['He is great who confers the most benefits.', 'emerson'],
        ['Man’s life is a progress, and not a station.', 'emerson'],
        ['So do we put our life into every act.', 'emerson'],
        ['For men are wiser than they know.', 'emerson'],
        ['It is one light which beams out of a thousand stars.', 'emerson'],
        ['The great man makes the great thing.', 'emerson'],
        ['The only way to have a friend is to be one.', 'emerson'],
        ['The gift, to be true, must be the flowing of the giver unto me, correspondent to my flowing unto him.', 'emerson'],
        ['The only gift is a portion of thyself.', 'emerson'],
        ['Nothing can bring you peace but yourself.', 'emerson'],
        ['Insist on yourself; never imitate.', 'emerson'],
        ['Nothing great was ever achieved without enthusiasm.', 'emerson'],
        ['There is no penalty to virtue; no penalty to wisdom; they are proper additions of being.', 'emerson'],
        ['There can be no excess to love; none to knowledge; none to beauty,', 'emerson'],
        ['The soul active sees absolute truth and utters truth, or creates.', 'emerson'],
        ['The universe is wider than our views of it.', 'thoreau'],
        ['Could a greater miracle take place than for us to look through each other’s eyes for an instant?', 'thoreau'],
        ['I think that we may safely trust a good deal more than we do.', 'thoreau'],
        ['Nature is as well adapted to our weakness as to our strength.', 'thoreau'],
        ['Who shall say what prospect life offers to another?', 'thoreau'],
        ['Every child begins the world again, to some extent, and loves to stay out doors, even in wet and cold.', 'thoreau'],
        ['One piece of good sense would be more memorable than a monument as high as the moon.', 'thoreau'],
        ['I will endeavor to speak a good word for the truth.', 'thoreau'],
        ['How could youths better learn to live than by at once trying the experiment of living?', 'thoreau'],
        ['I have learned that the swiftest traveller is he that goes afoot.', 'thoreau'],
        ['The finest qualities of our nature, like the bloom on fruits, can be preserved only by the most delicate handling.', 'thoreau'],
        ['But alert and healthy natures remember that the sun rose clear.', 'thoreau'],
        ['Nature and human life are as various as our several constitutions.', 'thoreau'],
        ['To anticipate, not the sunrise and the dawn merely, but, if possible, Nature herself!', 'thoreau'],
        ['The life which men praise and regard as successful is but one kind.', 'thoreau'],
        ['I love better to see stones in place.', 'thoreau'],
        ['If you have built castles in the air, your work need not be lost; that is where they should be.', 'thoreau'],
        ['Now put the foundations under them.', 'thoreau'],
        ['Only that day dawns to which we are awake.', 'thoreau'],
        ['The sun is but a morning star.', 'thoreau'],
        ['There is more day to dawn.', 'thoreau'],
    ];
    $catalog = [
        'verses' => array_map(static fn($row) => ['text' => $row[0], 'reference' => $row[1]], $verses),
        'quotes' => array_map(static fn($row) => ['text' => $row[0], 'author' => $sources[$row[1]][0], 'source' => $sources[$row[1]][1]], $quotes),
    ];
    return $catalog;
}

function ess_daily_inspiration(?DateTimeImmutable $date = null): array
{
    $date = ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Africa/Windhoek'));
    $civilDate = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    $day = (int) floor($civilDate->getTimestamp() / 86400);
    $cycle = (int) floor($day / 2);
    $catalog = ess_inspiration_catalog();
    $verseCount = count($catalog['verses']);
    $quoteCount = count($catalog['quotes']);
    return [
        'type' => $day % 2 === 0 ? 'verse' : 'quote',
        'verse' => $catalog['verses'][($cycle % $verseCount + $verseCount) % $verseCount],
        'quote' => $catalog['quotes'][(($cycle * 7 + 13) % $quoteCount + $quoteCount) % $quoteCount],
    ];
}

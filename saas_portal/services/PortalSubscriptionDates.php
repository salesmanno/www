<?php
final class PortalSubscriptionDates {
    public static function today(): string {
        return (new DateTimeImmutable('now',new DateTimeZone('Europe/Oslo')))->format('Y-m-d');
    }
    public static function valid($value): string {
        if (!is_string($value)) throw new InvalidArgumentException('Oppgi en gyldig forfallsdato.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Oslo'));
        if (!$date || $date->format('Y-m-d')!==$value || $value<'1000-01-01') throw new InvalidArgumentException('Oppgi en gyldig forfallsdato.');
        return $value;
    }
    public static function nextMonth(string $value): string {
        $start=new DateTimeImmutable(self::valid($value),new DateTimeZone('Europe/Oslo'));
        $next=$start->modify('first day of next month');
        return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$start->format('d'),(int)$next->format('t')))->format('Y-m-d');
    }
    public static function days(string $today,string $due): int {
        $zone=new DateTimeZone('Europe/Oslo');
        return (int)(new DateTimeImmutable(self::valid($today),$zone))->diff(new DateTimeImmutable(self::valid($due),$zone))->format('%r%a');
    }
}

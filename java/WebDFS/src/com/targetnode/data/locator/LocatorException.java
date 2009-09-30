package com.targetnode.data.locator;

/**
 * @author shane
 */
public class LocatorException extends Exception{

   public LocatorException( Exception e ){
       super(e);
   }

   public LocatorException( String s ){
       super(s);
   }
}
